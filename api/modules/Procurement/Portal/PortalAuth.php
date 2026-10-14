<?php

namespace Modules\Procurement\Portal;

use App\Core\Audit\Auditor;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Procurement\Models\Bidder;
use Modules\Procurement\Models\PortalSession;
use Modules\Procurement\Models\Tender;
use Modules\Procurement\Support\ProcurementError;

/**
 * PRC-07: the supplier portal's identity, separate from tenant users.
 *
 * - A bidder's **link token**: 48 letters and digits, kept as its sha256,
 *   valid until the tender's submission deadline; a new one replaces the
 *   old (resend).
 * - A **portal session**: `pts_` + 48 letters and digits, kept as its
 *   sha256, for 8 hours (config `procurement.portal.session_hours`), bound
 *   to one tenant (the row lives under its RLS), one tender and one bidder.
 * - The tenant of a token is found through the security-definer function
 *   `procurement_portal_tenant` (ADR 002), which returns nothing but the id;
 *   everything after runs in that tenant's context.
 * - Sessions end at sign-out, when the bidder is removed or the tender
 *   cancelled; every start and end is audited with the bidder and IP.
 */
class PortalAuth
{
    public const LINK_LENGTH = 48;

    public const SESSION_PREFIX = 'pts_';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** A new link token for the bidder (the previous one stops working). Returned once, never stored. */
    public function issueLink(Bidder $bidder, Tender $tender): string
    {
        $token = Str::random(self::LINK_LENGTH);
        $bidder->forceFill(['token_hash' => hash('sha256', $token), 'token_expires_at' => $tender->deadline_at, 'link_sent_at' => CarbonImmutable::now()])->save();

        return $token;
    }

    /**
     * Exchange a link token for a session; the tenant is entered.
     *
     * @return array{0: string, 1: PortalSession}
     */
    public function start(string $link, ?string $ip, ?string $userAgent): array
    {
        if (preg_match('/^[A-Za-z0-9]{'.self::LINK_LENGTH.'}$/', $link) !== 1) {
            throw ProcurementError::make('portal_link_invalid', status: 401);
        }

        $hash = hash('sha256', $link);
        $tenantId = $this->tenantOf('link', $hash) ?? throw ProcurementError::make('portal_link_invalid', status: 401);
        $this->tenants->set($tenantId);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($hash, $ip, $userAgent) {
            $bidder = Bidder::query()->where('token_hash', $hash)->whereNull('removed_at')->lockForUpdate()->first();
            $tender = $bidder === null ? null : Tender::query()->find($bidder->tender_id);

            if ($bidder === null || $tender === null || $tender->status === Tender::CANCELLED || $tender->status === Tender::DRAFT
                || $bidder->token_expires_at === null || $bidder->token_expires_at->isPast()) {
                throw ProcurementError::make('portal_link_invalid', status: 401);
            }

            $token = self::SESSION_PREFIX.Str::random(self::LINK_LENGTH);
            $session = PortalSession::query()->create([
                'bidder_id' => $bidder->id,
                'tender_id' => $tender->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => CarbonImmutable::now()->addHours((int) config('procurement.portal.session_hours', 8)),
                'ip' => $ip,
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            ]);
            $bidder->forceFill(['last_seen_at' => CarbonImmutable::now()])->save();
            $this->auditor->record('procurement.portal.session_start', $bidder, null, ['tender' => $tender->id, 'session' => $session->id, 'ip' => $ip], ['ip' => $ip]);

            return [$token, $session];
        });
    }

    /** The live session a bearer token names, with its tenant entered; null otherwise. */
    public function resolve(?string $token): ?PortalSession
    {
        if ($token === null || preg_match('/^'.self::SESSION_PREFIX.'[A-Za-z0-9]{'.self::LINK_LENGTH.'}$/', $token) !== 1) {
            return null;
        }

        $hash = hash('sha256', $token);
        $tenantId = $this->tenantOf('session', $hash);

        if ($tenantId === null) {
            return null;
        }

        $this->tenants->set($tenantId);
        $session = PortalSession::query()->with(['bidder', 'tender'])->where('token_hash', $hash)->first();

        if ($session === null || $session->revoked_at !== null || $session->expires_at->isPast()
            || $session->bidder->removed_at !== null || $session->tender->status === Tender::CANCELLED) {
            $this->tenants->set(null);

            return null;
        }

        if ($session->last_used_at === null || $session->last_used_at->lt(CarbonImmutable::now()->subMinute())) {
            $session->forceFill(['last_used_at' => CarbonImmutable::now()])->save();
        }

        return $session;
    }

    public function end(PortalSession $session, ?string $ip): void
    {
        $session->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        $this->auditor->record('procurement.portal.session_end', $session->bidder, null, ['tender' => $session->tender_id, 'session' => $session->id, 'ip' => $ip], ['ip' => $ip]);
    }

    /** Every session of a tender ends (cancelled). */
    public function revokeTender(Tender $tender): void
    {
        PortalSession::query()->where('tender_id', $tender->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);
    }

    /** Every session of a bidder ends (removed). */
    public function revokeBidder(Bidder $bidder): void
    {
        PortalSession::query()->where('bidder_id', $bidder->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now()]);
    }

    /** The tenant id of a token hash (`link`, `session` or `notice`), across tenants. */
    public function tenantOf(string $kind, string $hash): ?string
    {
        return DB::selectOne('select procurement_portal_tenant(?, ?) as tenant_id', [$kind, $hash])?->tenant_id;
    }
}
