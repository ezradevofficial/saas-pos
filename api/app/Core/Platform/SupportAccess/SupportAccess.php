<?php

namespace App\Core\Platform\SupportAccess;

use App\Core\Audit\AuditContext;
use App\Core\Audit\Auditor;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\SupportAccess\Models\PlatformSupportRequest;
use App\Core\Platform\SupportAccess\Models\SupportAccessGrant;
use App\Core\Rbac\PermissionRegistry;
use App\Core\Tenancy\DevicePairing;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * RBAC-13, ADM-03: platform staff see a tenant only after the tenant
 * grants time-limited access, and every action in that window is logged
 * in both audit chains and visible to the tenant.
 *
 * - Staff ask (request()): a reason, 1 to 72 hours, read or write. The
 *   tenant's Owners and Admins are told in the app and by email.
 * - The tenant approves (choosing whether payroll, HR-sensitive and
 *   payment data are included; recorded), denies, and can revoke at any
 *   time. Staff can cancel a pending request and end their session.
 * - An approved grant opens a session: a support principal (a tenant user
 *   marked with the grant, no password or contact, never able to sign in)
 *   whose token comes from a one-time entry code and expires with the
 *   grant. Its permissions come from the grant (permits()), which
 *   ScopeResolver asks; the support middleware logs every request.
 * - Access ends at expiry: the token is refused from then on, and
 *   `support:expire` marks the grant and deactivates the principal.
 *
 * Grants are tenant rows under RLS; the console lists its mirror
 * (platform_support_requests, platform role only).
 */
class SupportAccess
{
    /** Seconds the console's entry code stays usable. */
    public const ENTRY_CODE_SECONDS = 60;

    /** Actions a read-only session may hold. */
    public const READ_ACTIONS = ['view', 'view_all'];

    /**
     * RBAC-13d (1): never granted to a support session: identity and
     * roles, subscription changes, domains, security settings, the full
     * data export, audit exports, provider credentials, module changes and
     * support access itself; and everything that makes a credential, an
     * outbound URL or a scheduled action that could outlive the grant:
     * device pairing, attendance devices, automation rules (webhooks and
     * schedules), notification texts and settings, public lead forms, the
     * store's WhatsApp token, document shares, and supplier portal links.
     * Every entry must name real catalogue permissions (SupportAccessNeverTest).
     */
    public const NEVER = [
        'core.role.create', 'core.role.edit', 'core.role.archive', 'core.role.assign',
        'core.user.invite', 'core.user.edit', 'core.user.deactivate',
        'core.settings.edit', 'core.domain.manage', 'core.subscription.manage', 'core.onboarding.modules',
        'core.tenant.export', 'core.audit.export', 'core.access_review.export',
        'core.fiscal.configure', 'core.payment_method.configure',
        'core.support_access.*',
        'core.device.create', 'core.device.pair', 'hr.device.manage',
        'core.automation.edit', 'core.notification_template.edit', 'core.notification_settings.edit',
        'crm.settings.manage', 'ecommerce.store.manage', 'pos.sale.share',
        'procurement.tender.create', 'procurement.rfq.create',
    ];

    /**
     * RBAC-13d (2): payroll, HR-sensitive and payment data, granted only
     * when the tenant includes sensitive data in the grant.
     */
    public const SENSITIVE = [
        'payroll.*',
        'hr.bank.*', 'hr.pay.*', 'hr.identity.*', 'hr.medical.*', 'hr.disciplinary.*', 'hr.document.*',
        'core.payment.*', 'core.payment_method.*', 'core.subscription.*',
        'accounting.bank.*', 'accounting.payment.*',
        'loyalty.gift_card.*',
    ];

    /**
     * Review M6: field-rule fields hidden from a session whose grant leaves
     * sensitive data out (HR's personal and sensitive groups, resource
     * `hr_employee`; docs/modules/hr.md HR-09, HR-11).
     */
    public const SENSITIVE_FIELDS = [
        'hr_employee' => ['identifiers', 'date_of_birth', 'pay_accounts', 'pay', 'medical_notes', 'disciplinary', 'personal_email', 'personal_phone', 'address', 'emergency_contacts'],
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditContext $audit,
        private readonly Auditor $auditor,
        private readonly PlatformAuditor $platformAudit,
        private readonly SupportNotices $notices,
        private readonly PermissionRegistry $catalogue,
    ) {}

    // ----- Staff side -------------------------------------------------------------------------

    /** RBAC-13a: ask $tenant for access. One open request (pending or active) per staff member and tenant. */
    public function request(string $tenant, PlatformUser $staff, string $reason, string $mode, int $hours): SupportAccessGrant
    {
        $grant = $this->tenants->run($tenant, function () use ($staff, $reason, $mode, $hours) {
            $grant = $this->asStaff($staff, function () use ($staff, $reason, $mode, $hours) {
                DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['support_access:'.$this->tenants->require().':'.$staff->id]);

                $open = SupportAccessGrant::query()->where('platform_user_id', $staff->id)
                    ->where(fn ($q) => $q->where('status', SupportAccessGrant::PENDING)
                        ->orWhere(fn ($q) => $q->where('status', SupportAccessGrant::APPROVED)->where('expires_at', '>', now())))
                    ->exists();

                if ($open) {
                    throw new ApiException(409, 'support_request_open', __('platform.support.request_open'));
                }

                return SupportAccessGrant::query()->create([
                    'platform_user_id' => $staff->id,
                    'staff_name' => $staff->name,
                    'staff_email' => $staff->email,
                    'reason' => $reason,
                    'mode' => $mode,
                    'hours' => $hours,
                    'requested_at' => CarbonImmutable::now(),
                ]);
            });

            rescue(fn () => $this->notices->requested($grant), report: true);

            return $grant;
        });

        $this->mirror($tenant, $grant);
        $this->platformAudit->record('platform.support.request', null, null, ['tenant_id' => $tenant, 'grant_id' => $grant->id, 'mode' => $mode, 'hours' => $hours, 'reason' => $reason]);

        return $grant;
    }

    /** Staff withdraw their own pending request. */
    public function cancel(PlatformSupportRequest $request, PlatformUser $staff): SupportAccessGrant
    {
        $grant = $this->inTenantAsStaff($request, $staff, function (SupportAccessGrant $grant) {
            if ($grant->status !== SupportAccessGrant::PENDING) {
                throw new ApiException(422, 'support_request_not_pending', __('platform.support.not_pending'));
            }

            $grant->fill(['status' => SupportAccessGrant::CANCELLED, 'ended_at' => now(), 'end_reason' => 'cancelled'])->save();

            return $grant;
        });

        $this->platformAudit->record('platform.support.cancel', null, null, ['tenant_id' => $request->tenant_ref, 'grant_id' => $grant->id]);

        return $grant;
    }

    /**
     * RBAC-13c: open a session for an active grant of $staff: the support
     * principal is made (once) and a one-time entry code is returned, as
     * `<tenant id>.<secret>`, usable for ENTRY_CODE_SECONDS.
     *
     * @return array{entry_code: string, expires_at: string, grant: SupportAccessGrant}
     */
    public function openSession(PlatformSupportRequest $request, PlatformUser $staff): array
    {
        $secret = Str::random(48);

        $grant = $this->inTenantAsStaff($request, $staff, function (SupportAccessGrant $grant) use ($secret) {
            if (! $grant->isActive()) {
                throw new ApiException(403, 'support_access_not_granted', __('platform.support.not_granted'));
            }

            $grant->principal_user_id ??= $this->principal($grant)->id;
            $grant->forceFill([
                'entry_code_hash' => hash('sha256', $secret),
                'entry_code_expires_at' => CarbonImmutable::now()->addSeconds(self::ENTRY_CODE_SECONDS),
            ])->save();

            return $grant;
        });

        $this->platformAudit->record('platform.support.session_open', null, null, ['tenant_id' => $request->tenant_ref, 'grant_id' => $grant->id]);

        return [
            'entry_code' => $request->tenant_ref.'.'.$secret,
            'expires_at' => CarbonImmutable::now()->addSeconds(self::ENTRY_CODE_SECONDS)->toIso8601String(),
            'grant' => $grant,
        ];
    }

    /** Staff end their own session (and the grant with it). */
    public function endByStaff(PlatformSupportRequest $request, PlatformUser $staff): SupportAccessGrant
    {
        $grant = $this->inTenantAsStaff($request, $staff, function (SupportAccessGrant $grant) {
            if ($grant->status !== SupportAccessGrant::APPROVED) {
                throw new ApiException(422, 'support_access_not_active', __('platform.support.not_active'));
            }

            return $this->close($grant, SupportAccessGrant::ENDED, 'ended_by_staff');
        });

        $this->platformAudit->record('platform.support.end', null, null, ['tenant_id' => $request->tenant_ref, 'grant_id' => $grant->id]);

        return $grant;
    }

    /**
     * The tenant web exchanges the console's entry code for the session's
     * token (once). Null when the code is unknown, used or stale.
     *
     * @return array{token: string, grant: SupportAccessGrant, tenant: Tenant}|null
     */
    public function exchange(string $code, string $ip, string $userAgent): ?array
    {
        [$tenantId, $secret] = array_pad(explode('.', $code, 2), 2, '');

        if (! Str::isUuid($tenantId) || strlen($secret) !== 48) {
            return null;
        }

        $result = $this->tenants->run($tenantId, function () use ($secret, $ip, $userAgent) {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($secret, $ip, $userAgent) {
                $grant = SupportAccessGrant::query()->where('entry_code_hash', hash('sha256', $secret))
                    ->where('entry_code_expires_at', '>', now())->lockForUpdate()->first();

                if ($grant === null || ! $grant->isActive() || $grant->principal_user_id === null) {
                    return null;
                }

                $principal = User::query()->findOrFail($grant->principal_user_id);
                $first = $grant->session_started_at === null;
                $grant->forceFill(['entry_code_hash' => null, 'entry_code_expires_at' => null, 'session_started_at' => $grant->session_started_at ?? now()])->saveQuietly();

                $token = $principal->createDeviceToken('support-session', $ip, $userAgent, ['*']);
                // The token ends with the grant, whatever else happens (Sanctum checks expires_at).
                $token->accessToken->forceFill(['expires_at' => $grant->expires_at])->save();

                $this->audit->actingAs($principal->id, ['platform_user_id' => $grant->platform_user_id, 'support_grant_id' => $grant->id], fn () => $this->auditor->record('core.support_session.start', $grant, null, ['mode' => $grant->mode, 'include_sensitive' => $grant->include_sensitive, 'expires_at' => $grant->expires_at->toIso8601String()]));

                if ($first) {
                    DB::connection(TenantContext::CONNECTION)->afterCommit(fn () => rescue(fn () => $this->notices->started($grant), report: true));
                }

                return ['token' => $token->plainTextToken, 'grant' => $grant, 'tenant' => Tenant::query()->findOrFail($this->tenants->require())];
            });
        });

        if ($result !== null) {
            $this->platformAudit->record('platform.support.session_start', null, null, ['tenant_id' => $tenantId, 'grant_id' => $result['grant']->id], PlatformUser::query()->find($result['grant']->platform_user_id));
        }

        return $result;
    }

    // ----- Tenant side ------------------------------------------------------------------------

    /** RBAC-13b: approve, with the sensitive-data choice recorded; access runs from now for the requested hours. */
    public function approve(SupportAccessGrant $grant, User $by, bool $includeSensitive, ?string $mode, ?string $note): SupportAccessGrant
    {
        $grant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($grant, $by, $includeSensitive, $mode, $note) {
            $grant = $this->locked($grant);

            if ($grant->status !== SupportAccessGrant::PENDING) {
                throw new ApiException(422, 'support_request_not_pending', __('platform.support.not_pending'));
            }

            $grant->fill([
                'status' => SupportAccessGrant::APPROVED,
                // The tenant may narrow write access to read-only, never widen it.
                'mode' => $mode === SupportAccessGrant::READ ? SupportAccessGrant::READ : $grant->mode,
                'include_sensitive' => $includeSensitive,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note,
                'expires_at' => CarbonImmutable::now()->addHours($grant->hours),
            ])->save();

            return $grant;
        });

        $this->mirror($this->tenants->require(), $grant);
        $this->platformAudit->record('platform.support.approved', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id, 'mode' => $grant->mode, 'include_sensitive' => $grant->include_sensitive, 'expires_at' => $grant->expires_at?->toIso8601String(), 'by_user_id' => $by->id, 'platform_user_id' => $grant->platform_user_id]);

        return $grant;
    }

    public function deny(SupportAccessGrant $grant, User $by, ?string $note): SupportAccessGrant
    {
        $grant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($grant, $by, $note) {
            $grant = $this->locked($grant);

            if ($grant->status !== SupportAccessGrant::PENDING) {
                throw new ApiException(422, 'support_request_not_pending', __('platform.support.not_pending'));
            }

            $grant->fill(['status' => SupportAccessGrant::DENIED, 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            return $grant;
        });

        $this->mirror($this->tenants->require(), $grant);
        $this->platformAudit->record('platform.support.denied', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id, 'by_user_id' => $by->id, 'platform_user_id' => $grant->platform_user_id]);

        return $grant;
    }

    /** RBAC-13b: the tenant ends an approval at once: the session's token stops working. */
    public function revoke(SupportAccessGrant $grant, User $by): SupportAccessGrant
    {
        $grant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($grant, $by) {
            $grant = $this->locked($grant);

            if ($grant->status !== SupportAccessGrant::APPROVED) {
                throw new ApiException(422, 'support_access_not_active', __('platform.support.not_active'));
            }

            $grant->revoked_by = $by->id;

            return $this->close($grant, SupportAccessGrant::REVOKED, 'revoked');
        });

        $this->platformAudit->record('platform.support.revoked', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id, 'by_user_id' => $by->id, 'platform_user_id' => $grant->platform_user_id]);

        return $grant;
    }

    /** The principal ends its own session from the tenant back office. */
    public function endInSession(User $principal): SupportAccessGrant
    {
        $grant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($principal) {
            $grant = SupportAccessGrant::query()->whereKey($principal->support_grant_id)->lockForUpdate()->firstOrFail();

            return $grant->status === SupportAccessGrant::APPROVED ? $this->close($grant, SupportAccessGrant::ENDED, 'ended_in_session') : $grant;
        });

        $this->platformAudit->record('platform.support.end', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id], $this->staffOf($grant));

        return $grant;
    }

    /** RBAC-13f: approvals past their end in the current tenant become expired; their principals are switched off. */
    public function expire(CarbonImmutable $at): int
    {
        $ids = SupportAccessGrant::query()->where('status', SupportAccessGrant::APPROVED)->where('expires_at', '<=', $at)->pluck('id');

        foreach ($ids as $id) {
            $grant = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $at) {
                $grant = SupportAccessGrant::query()->whereKey($id)->lockForUpdate()->first();

                return $grant !== null && $grant->status === SupportAccessGrant::APPROVED && $grant->expires_at <= $at
                    ? $this->close($grant, SupportAccessGrant::EXPIRED, 'expired')
                    : null;
            });

            if ($grant !== null) {
                $this->platformAudit->record('platform.support.expired', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id, 'platform_user_id' => $grant->platform_user_id]);
            }
        }

        return $ids->count();
    }

    // ----- The session -------------------------------------------------------------------------

    /** The grant of a support principal (loaded once per user instance). */
    public function grantOf(User $user): ?SupportAccessGrant
    {
        if ($user->support_grant_id === null) {
            return null;
        }

        if (! $user->relationLoaded('supportGrant')) {
            $user->setRelation('supportGrant', SupportAccessGrant::query()->find($user->support_grant_id));
        }

        return $user->getRelation('supportGrant');
    }

    /** Whether a support principal's session may act now: its grant is approved and not past its end. */
    public function sessionValid(User $user): bool
    {
        $grant = $this->grantOf($user);

        if ($grant === null || ! $grant->isActive()) {
            return false;
        }

        // Review H2: the staff member must still be active and allowed to hold support access.
        if (! $user->relationLoaded('supportStaff')) {
            $user->setRelation('supportStaff', PlatformUser::query()->find($grant->platform_user_id));
        }

        return (bool) $user->getRelation('supportStaff')?->allows('platform.support.request');
    }

    /**
     * The Sanctum check of a support session's token. A refused token is
     * logged in both chains (review M8): someone used a session that ended.
     */
    public function acceptsToken(User $principal): bool
    {
        if ($principal->isActive() && $this->sessionValid($principal)) {
            return true;
        }

        rescue(function () use ($principal) {
            $grant = $this->grantOf($principal);

            if ($grant === null || $this->tenants->id() === null) {
                return;
            }

            $this->audit->actingAs($principal->id, ['support_grant_id' => $grant->id], fn () => $this->auditor->record('core.support_session.refused', $grant, null, ['status' => $grant->effectiveStatus(), 'path' => mb_substr('/'.ltrim(request()->path(), '/'), 0, 500)]));
            $this->platformAudit->record('platform.support.token_refused', null, null, ['tenant_id' => $this->tenants->id(), 'grant_id' => $grant->id, 'platform_user_id' => $grant->platform_user_id]);
        }, report: true);

        return false;
    }

    /**
     * Review H2: a staff member deactivated or given another role loses
     * every support access at once, in every tenant: approved grants end,
     * pending requests are cancelled. Each tenant is handled in its own
     * context; a failure in one is reported and the others still end.
     *
     * @return int grants ended or cancelled
     */
    public function endForStaff(PlatformUser $staff): int
    {
        $count = 0;

        foreach (PlatformSupportRequest::query()->where('platform_user_id', $staff->id)->whereIn('status', [SupportAccessGrant::PENDING, SupportAccessGrant::APPROVED])->get() as $request) {
            try {
                $grant = $this->tenants->run($request->tenant_ref, fn () => DB::connection(TenantContext::CONNECTION)->transaction(function () use ($request) {
                    $grant = SupportAccessGrant::query()->whereKey($request->id)->lockForUpdate()->first();

                    return match ($grant?->status) {
                        SupportAccessGrant::APPROVED => $this->close($grant, SupportAccessGrant::ENDED, 'staff_changed'),
                        SupportAccessGrant::PENDING => tap($grant)->fill(['status' => SupportAccessGrant::CANCELLED, 'ended_at' => now(), 'end_reason' => 'staff_changed'])->save(),
                        default => $grant,
                    };
                }));

                if ($grant !== null) {
                    $this->mirror($request->tenant_ref, $grant);
                    $this->platformAudit->record('platform.support.staff_changed', null, null, ['tenant_id' => $request->tenant_ref, 'grant_id' => $grant->id, 'platform_user_id' => $staff->id, 'status' => $grant->status]);
                    $count++;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $count;
    }

    /**
     * RBAC-13d: may the support principal $user use $permission? A
     * catalogue permission, never in NEVER; not SENSITIVE unless the grant
     * includes sensitive data; and in a read-only session only a `view`
     * action. Always at tenant scope (ScopeResolver adds the module check).
     */
    public function permits(User $user, string $permission): bool
    {
        $grant = $this->grantOf($user);

        if ($grant === null || ! $grant->isActive() || ! $this->catalogue->has($permission)) {
            return false;
        }

        if (Str::is(self::NEVER, $permission)) {
            return false;
        }

        if (! $grant->include_sensitive && Str::is(self::SENSITIVE, $permission)) {
            return false;
        }

        return $grant->mode === SupportAccessGrant::WRITE || in_array(Str::afterLast($permission, '.'), self::READ_ACTIONS, true);
    }

    /**
     * RBAC-13e: one request of a session, in the tenant's audit (as the
     * principal, visible to the tenant) and in the platform chain.
     *
     * @param  array{method: string, route: ?string, path: string, status: int, ms: int}  $request
     */
    public function logRequest(User $principal, array $request): void
    {
        $grant = $this->grantOf($principal);

        if ($grant === null) {
            return;
        }

        $this->auditor->record('core.support_session.request', $grant, null, $request);
        $this->platformAudit->record('platform.support.request_made', null, null, ['tenant_id' => $this->tenants->require(), 'grant_id' => $grant->id, ...$request], $this->staffOf($grant));
    }

    // ----- Internals ---------------------------------------------------------------------------

    /**
     * End a grant: its status, the principal deactivated and its tokens
     * deleted, the console's mirror, and the tenant's managers told.
     * Runs in the tenant's context, inside the caller's transaction.
     */
    private function close(SupportAccessGrant $grant, string $status, string $reason): SupportAccessGrant
    {
        $grant->fill(['status' => $status, 'ended_at' => now(), 'end_reason' => $reason])->save();
        $grant->forceFill(['entry_code_hash' => null, 'entry_code_expires_at' => null])->saveQuietly();

        if ($grant->principal_user_id !== null) {
            $principal = User::query()->find($grant->principal_user_id);

            if ($principal !== null) {
                $principal->tokens()->delete();
                $this->disarm($principal);

                if ($principal->status !== User::STATUS_DEACTIVATED) {
                    $principal->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
                }
            }
        }

        $tenantId = $this->tenants->require();
        DB::connection(TenantContext::CONNECTION)->afterCommit(function () use ($tenantId, $grant) {
            $this->mirror($tenantId, $grant);
            $this->tenants->run($tenantId, fn () => rescue(fn () => $this->notices->ended($grant), report: true));
        });

        return $grant;
    }

    /**
     * Review H1: nothing the session made keeps acting after it ends.
     * Devices it touched (pairing codes, pairings; found in the tenant's
     * audit by the principal's entries) are suspended, and automation rules
     * it created or last changed are switched off. The permissions that make
     * these are refused to sessions already (NEVER); this is the backstop.
     */
    private function disarm(User $principal): void
    {
        $deviceIds = DB::connection(TenantContext::CONNECTION)->table('audit_logs')
            ->where('user_id', $principal->id)->where('auditable_type', (new Device)->getMorphClass())
            ->distinct()->pluck('auditable_id')->all();

        foreach (Device::query()->whereIn('id', $deviceIds)->whereIn('status', [Device::STATUS_ACTIVE, Device::STATUS_PENDING])->get() as $device) {
            app(DevicePairing::class)->suspend($device);
        }

        AutomationRule::query()->where('enabled', true)
            ->where(fn ($q) => $q->where('created_by', $principal->id)->orWhere('updated_by', $principal->id))
            ->get()->each(fn (AutomationRule $rule) => $rule->forceFill(['enabled' => false])->save());
    }

    /** The support principal of $grant: made once, active while the grant is. */
    private function principal(SupportAccessGrant $grant): User
    {
        $existing = User::query()->where('support_grant_id', $grant->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $locale = (string) (Tenant::query()->value('default_locale') ?: 'en');
        $user = new User([
            'name' => __('platform.support.principal_name', ['name' => $grant->staff_name], $locale),
            'email' => "support-{$grant->id}@support.invalid",
            'password' => Str::random(64),
            'locale' => $locale,
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->forceFill(['support_grant_id' => $grant->id])->save();

        return $user;
    }

    private function locked(SupportAccessGrant $grant): SupportAccessGrant
    {
        return SupportAccessGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Run $fn on the grant behind $request, in its tenant, as the system
     * naming the staff member, who must be the one who asked.
     *
     * @template T
     *
     * @param  callable(SupportAccessGrant): T  $fn
     * @return T
     */
    private function inTenantAsStaff(PlatformSupportRequest $request, PlatformUser $staff, callable $fn): mixed
    {
        if ($request->platform_user_id !== $staff->id) {
            abort(404);
        }

        $grant = $this->tenants->run($request->tenant_ref, fn () => $this->asStaff($staff, function () use ($request, $fn) {
            $grant = SupportAccessGrant::query()->whereKey($request->id)->lockForUpdate()->first() ?? abort(404);

            return $fn($grant);
        }));

        $this->mirror($request->tenant_ref, $grant);

        return $grant;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function asStaff(PlatformUser $staff, callable $fn): mixed
    {
        return $this->audit->actingAs(null, ['platform_user_id' => $staff->id, 'staff_name' => $staff->name], fn () => DB::connection(TenantContext::CONNECTION)->transaction($fn));
    }

    /** Keep the console's mirror in step with the tenant's grant. */
    private function mirror(string $tenantId, SupportAccessGrant $grant): void
    {
        $name = (string) $this->tenants->run($tenantId, fn () => Tenant::query()->whereKey($tenantId)->value('name'));

        PlatformSupportRequest::query()->updateOrCreate(['id' => $grant->id], [
            'tenant_ref' => $tenantId,
            'tenant_name' => $name,
            'platform_user_id' => $grant->platform_user_id,
            'reason' => $grant->reason,
            'mode' => $grant->mode,
            'hours' => $grant->hours,
            'include_sensitive' => $grant->include_sensitive,
            'status' => $grant->status,
            'requested_at' => $grant->requested_at,
            'decided_at' => $grant->decided_at,
            'expires_at' => $grant->expires_at,
            'ended_at' => $grant->ended_at,
            'end_reason' => $grant->end_reason,
        ]);
    }

    private function staffOf(SupportAccessGrant $grant): ?PlatformUser
    {
        return PlatformUser::query()->find($grant->platform_user_id);
    }
}
