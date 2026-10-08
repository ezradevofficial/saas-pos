<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalEmailToken;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditContext;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Identity\Services\TwoFactor;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * APR-08: approve or reject from an email.
 *
 * When an approval email (requested, reminder, escalated) is sent, each
 * pending approver gets two single-use links (approve, reject) to the web
 * app's confirm page `/approvals/email/{token}`, valid 72 hours, bound to
 * the assignment and the user; the token is stored as its sha256 only and
 * a new email replaces the previous unused links. Opening a link (GET)
 * changes nothing: it answers what would happen, or that the user must
 * sign in instead (the link was used or expired, the request is no longer
 * waiting for them, or a role of theirs requires two-factor). Confirming
 * (POST) decides as that user (audited, `via: email`) and spends every
 * link of the assignment. Links need the node's `allow_email` (default on).
 * Delegates act in the app, not by email. Both endpoints are rate-limited.
 */
class EmailApprovals
{
    public const TTL_HOURS = 72;

    // L7: their own rate-limit bucket, per IP (ApprovalsServiceProvider).
    public const LIMITER = 'approval-email';

    public const PER_MINUTE = 20;

    public const OK = 'confirm';

    public const SIGN_IN = 'sign_in_required';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalLog $log,
        private readonly TwoFactor $twoFactor,
        private readonly AuditContext $audit,
    ) {}

    /**
     * MailActions provider: approve and reject links for the delivery's
     * user on the request its link names.
     *
     * @return list<array{label: string, url: string}>
     */
    public function links(NotificationDelivery $delivery): array
    {
        if ($delivery->channel !== Channels::EMAIL || preg_match('#^/approvals/([0-9a-f-]{36})$#', (string) $delivery->link, $m) !== 1) {
            return [];
        }

        $request = ApprovalRequest::query()->find($m[1]);

        if ($request === null || ! $request->isPending() || ($request->config['allow_email'] ?? true) === false) {
            return [];
        }

        $assignment = $request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)
            ->where('user_id', $delivery->user_id)->first();

        if ($assignment === null) {
            return [];
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($assignment, $delivery) {
            $now = CarbonImmutable::now();
            ApprovalEmailToken::query()->where('assignment_id', $assignment->id)->whereNull('used_at')->where('expires_at', '>', $now)
                ->update(['expires_at' => $now, 'updated_at' => $now]);

            $links = [];

            foreach ([ApprovalDecisions::APPROVE, ApprovalDecisions::REJECT] as $action) {
                $token = Str::random(ApprovalEmailToken::TOKEN_LENGTH);
                ApprovalEmailToken::create([
                    'assignment_id' => $assignment->id,
                    'user_id' => $assignment->user_id,
                    'action' => $action,
                    'token_hash' => ApprovalEmailToken::hashToken($token),
                    'expires_at' => $now->addHours(self::TTL_HOURS),
                ]);
                $links[] = [
                    'label' => __('approvals.email.'.$action, [], $delivery->locale),
                    'url' => rtrim((string) config('app.frontend_url'), '/').'/approvals/email/'.$token,
                ];
            }

            return $links;
        });
    }

    /**
     * What the link would do, with the token's tenant entered (404 for an
     * unknown token).
     *
     * @return array{status: string, reason: ?string, action: string, approval_id: string, token: ApprovalEmailToken, request: ApprovalRequest, user: User}
     */
    public function open(string $token): array
    {
        $hash = ApprovalEmailToken::hashToken($token);
        $tenantId = DB::selectOne('select approval_tenant_for_email_token(?) as tenant_id', [$hash])?->tenant_id;
        abort_if($tenantId === null, 404);

        $this->tenants->set($tenantId);
        $row = ApprovalEmailToken::query()->where('token_hash', $hash)->first();
        abort_if($row === null, 404);

        $assignment = ApprovalAssignment::query()->findOrFail($row->assignment_id);
        $request = ApprovalRequest::query()->findOrFail($assignment->request_id);
        $user = User::query()->find($row->user_id);
        abort_if($user === null || ! $user->isActive(), 404);

        $reason = match (true) {
            $row->used_at !== null => 'used',
            ! $assignment->isPending() || ! $request->isPending() || $assignment->step !== $request->step => 'not_waiting',
            $row->expires_at->lessThanOrEqualTo(CarbonImmutable::now()) => 'expired',
            // M5: a second factor (required or chosen) means a link alone is not enough; so does a locked account.
            $user->locked_until !== null && $user->locked_until->isFuture() => 'locked',
            $user->hasTwoFactor() || $this->twoFactor->required($user) => 'two_factor',
            default => null,
        };

        return [
            'status' => $reason === null ? self::OK : self::SIGN_IN,
            'reason' => $reason,
            'action' => $row->action,
            'approval_id' => $request->id,
            'token' => $row,
            'request' => $request,
            'user' => $user,
        ];
    }

    /** Confirm the link: decide as its user and spend the assignment's links. */
    public function confirm(string $token, ?string $comment, ?string $ip, ?string $userAgent): ApprovalRequest
    {
        $opened = $this->open($token);

        if ($opened['status'] !== self::OK) {
            throw new ApiException(403, self::SIGN_IN, __('approvals.email.sign_in.'.$opened['reason']), [], ['reason' => $opened['reason'], 'approval_id' => $opened['approval_id']]);
        }

        $this->audit->setUserId($opened['user']->id)->setIp($ip)->setUserAgent($userAgent);

        return $this->decisions->transaction(function () use ($opened, $comment) {
            $spent = ApprovalEmailToken::query()->where('assignment_id', $opened['token']->assignment_id)->whereNull('used_at')
                ->where('expires_at', '>', CarbonImmutable::now())
                ->update(['used_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

            // Another request spent the links first.
            if ($spent === 0) {
                throw new ApiException(403, self::SIGN_IN, __('approvals.email.sign_in.used'), [], ['reason' => 'used', 'approval_id' => $opened['approval_id']]);
            }

            return $this->decisions->decide($opened['request'], $opened['user'], $opened['action'], $comment, 'email');
        });
    }
}
