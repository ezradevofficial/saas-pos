<?php

namespace App\Core\Branding\Domains;

use App\Core\Audit\Auditor;
use App\Core\Branding\Models\TenantDomain;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BR-05: the tenant's custom domains.
 *
 * - add: a pending domain with a random token. The tenant publishes a TXT
 *   record `{txt_prefix}.{host}` with the value `{txt_value_prefix}{token}`.
 * - check: looks the record up (DnsResolver) and marks the domain
 *   verified when it is there. A pending domain still unproven after
 *   `branding.domains.pending_days` becomes failed; "Check now" makes a
 *   failed domain pending again.
 * - archive: the domain stops working at once (TLS ask, sign-in, email
 *   sender) and its host may be added again, here or by another tenant.
 *
 * Every change is audited as `core.domain.*` (AUD-01). Verification runs
 * in the tenant's context, on the runtime connection.
 */
class TenantDomains
{
    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Auditor $auditor,
    ) {}

    public static function recordName(TenantDomain $domain): string
    {
        return config('branding.domains.txt_prefix').'.'.$domain->host;
    }

    public static function recordValue(TenantDomain $domain): string
    {
        return config('branding.domains.txt_value_prefix').$domain->verification_token;
    }

    public function add(string $host, ?User $by): TenantDomain
    {
        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($host, $by) {
                $domain = TenantDomain::create([
                    'host' => $host,
                    'status' => TenantDomain::PENDING,
                    'pending_since' => CarbonImmutable::now(),
                    'verification_token' => Str::lower(Str::random(32)),
                    'created_by' => $by?->id,
                ]);

                $this->auditor->record('core.domain.add', $domain, null, ['host' => $host, 'status' => TenantDomain::PENDING]);

                return $domain;
            });
        } catch (UniqueConstraintViolationException) {
            $message = __('branding.errors.domain_taken');

            throw new ApiException(422, 'domain_taken', $message, ['host' => [$message]]);
        }
    }

    /** Look the TXT record up now and update the domain (pending or failed; verified ones stay verified). */
    public function check(TenantDomain $domain, ?CarbonImmutable $now = null): TenantDomain
    {
        $now ??= CarbonImmutable::now();

        if ($domain->archived_at !== null || $domain->status === TenantDomain::VERIFIED) {
            return $domain;
        }

        $records = $this->dns->txt(self::recordName($domain));
        $found = $records !== null && in_array(self::recordValue($domain), array_map('trim', $records), true);

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $records, $found, $now) {
            $domain = TenantDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $before = $domain->status;

            if ($found) {
                $domain->forceFill(['status' => TenantDomain::VERIFIED, 'verified_at' => $now, 'checked_at' => $now, 'failure' => null])->save();
            } else {
                $expired = $domain->pending_since !== null
                    && $domain->pending_since->lt($now->subDays((int) config('branding.domains.pending_days', 3)));
                $domain->forceFill([
                    'status' => $expired && $before === TenantDomain::PENDING ? TenantDomain::FAILED : $before,
                    'checked_at' => $now,
                    'failure' => $records === null ? 'lookup_failed' : 'record_missing',
                ])->save();
            }

            if ($domain->status !== $before) {
                $this->auditor->record('core.domain.'.($found ? 'verify' : 'fail'), $domain, ['status' => $before], ['status' => $domain->status, 'host' => $domain->host]);
            }

            return $domain;
        });
    }

    /** "Check now": a failed domain is pending again, then checked. */
    public function retry(TenantDomain $domain): TenantDomain
    {
        if ($domain->status === TenantDomain::FAILED) {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain) {
                $domain->forceFill(['status' => TenantDomain::PENDING, 'pending_since' => CarbonImmutable::now()])->save();
                $this->auditor->record('core.domain.retry', $domain, ['status' => TenantDomain::FAILED], ['status' => TenantDomain::PENDING]);
            });
        }

        return $this->check($domain);
    }

    /** Check every pending domain of the current tenant (domains:verify). */
    public function checkPending(?CarbonImmutable $now = null): int
    {
        $domains = TenantDomain::query()->where('status', TenantDomain::PENDING)->whereNull('archived_at')->orderBy('created_at')->get();

        foreach ($domains as $domain) {
            $this->check($domain, $now);
        }

        return $domains->count();
    }

    public function archive(TenantDomain $domain): TenantDomain
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain) {
            $domain = TenantDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($domain->archived_at === null) {
                $domain->forceFill(['archived_at' => CarbonImmutable::now()])->save();
                $this->auditor->record('core.domain.archive', $domain, ['host' => $domain->host, 'status' => $domain->status], ['archived' => true]);
            }

            return $domain;
        });
    }

    /** The verified, not archived domain of the current tenant with this host. */
    public function verified(string $host): ?TenantDomain
    {
        return TenantDomain::query()
            ->where('host', Str::lower($host))
            ->where('status', TenantDomain::VERIFIED)
            ->whereNull('archived_at')
            ->first();
    }
}
