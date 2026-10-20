<?php

namespace App\Core\Sso\Domains;

use App\Core\Audit\Auditor;
use App\Core\Branding\Domains\DnsResolver;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Sso\Models\SsoDomain;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AUTH-11, ADR 023: the tenant's email domains, proven as BR-05 proves
 * custom domains: a TXT record `{txt_prefix}.{domain}` holding
 * `{txt_value_prefix}{token}`, through the same DnsResolver.
 *
 * - add: a pending claim. Several tenants may claim one domain; the first
 *   proof wins (unique among verified rows) and a later one fails with
 *   `claimed_elsewhere`.
 * - check: pending claims are verified when the record is there, and fail
 *   after `branding.domains.pending_days`; verified domains are re-checked
 *   daily (`domains:verify`) and drop to failed after MISSES_ALLOWED misses
 *   in a row (failed lookups do not count), which stops SSO for them.
 * - failed claims are archived after FAILED_KEEP_DAYS.
 *
 * Every change is audited as `core.sso.domain_*` (AUD-01). Runs in the
 * tenant's context, on the runtime connection.
 */
class SsoDomains
{
    public const MISSES_ALLOWED = 3;

    public const FAILED_KEEP_DAYS = 7;

    public const RECHECK_HOURS = 20;

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Auditor $auditor,
    ) {}

    public static function recordName(SsoDomain $domain): string
    {
        return config('branding.domains.txt_prefix').'.'.$domain->domain;
    }

    public static function recordValue(SsoDomain $domain): string
    {
        return config('branding.domains.txt_value_prefix').$domain->verification_token;
    }

    /** The domain part of an email address, lower-cased, or null. */
    public static function domainOf(?string $email): ?string
    {
        if ($email === null || substr_count($email, '@') !== 1) {
            return null;
        }

        $domain = rtrim(Str::lower(trim(Str::after($email, '@'))), '.');

        return $domain === '' ? null : $domain;
    }

    /** True when $email's domain is a verified, unarchived email domain of the current tenant. */
    public function covers(?string $email): bool
    {
        $domain = self::domainOf($email);

        return $domain !== null && SsoDomain::query()
            ->where('domain', $domain)->where('status', SsoDomain::VERIFIED)->whereNull('archived_at')->exists();
    }

    /** True when $domain is a verified, unarchived email domain of the current tenant. */
    public function isVerified(string $domain): bool
    {
        return SsoDomain::query()->where('domain', Str::lower($domain))->where('status', SsoDomain::VERIFIED)->whereNull('archived_at')->exists();
    }

    public function hasVerified(): bool
    {
        return SsoDomain::query()->where('status', SsoDomain::VERIFIED)->whereNull('archived_at')->exists();
    }

    public function add(string $domain, ?User $by): SsoDomain
    {
        if (SsoDomain::query()->where('domain', $domain)->whereNull('archived_at')->exists()) {
            $message = __('sso.errors.domain_added');

            throw new ApiException(422, 'domain_added', $message, ['domain' => [$message]]);
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $by) {
            $row = SsoDomain::create([
                'domain' => $domain,
                'status' => SsoDomain::PENDING,
                'pending_since' => CarbonImmutable::now(),
                'verification_token' => Str::lower(Str::random(32)),
                'created_by' => $by?->id,
            ]);

            $this->auditor->record('core.sso.domain_add', $row, null, ['domain' => $domain, 'status' => SsoDomain::PENDING]);

            return $row;
        });
    }

    /** Look the TXT record up now and update the domain. */
    public function check(SsoDomain $domain, ?CarbonImmutable $now = null): SsoDomain
    {
        $now ??= CarbonImmutable::now();

        if ($domain->archived_at !== null || $domain->status === SsoDomain::FAILED) {
            return $domain;
        }

        $records = $this->dns->txt(self::recordName($domain));
        $found = $records !== null && in_array(self::recordValue($domain), array_map('trim', $records), true);

        return $domain->status === SsoDomain::VERIFIED
            ? $this->recheck($domain, $records, $found, $now)
            : $this->prove($domain, $records, $found, $now);
    }

    /** "Check now": a failed claim is pending again, then checked. */
    public function retry(SsoDomain $domain): SsoDomain
    {
        if ($domain->status === SsoDomain::FAILED) {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain) {
                $domain->forceFill(['status' => SsoDomain::PENDING, 'pending_since' => CarbonImmutable::now(), 'failed_at' => null, 'missed_checks' => 0])->save();
                $this->auditor->record('core.sso.domain_retry', $domain, ['status' => SsoDomain::FAILED], ['status' => SsoDomain::PENDING]);
            });
        }

        return $this->check($domain);
    }

    /** @return int domains looked at, for `domains:verify` in the current tenant */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        $due = SsoDomain::query()->whereNull('archived_at')
            ->where(fn ($q) => $q->where('status', SsoDomain::PENDING)
                ->orWhere(fn ($v) => $v->where('status', SsoDomain::VERIFIED)
                    ->where(fn ($c) => $c->whereNull('checked_at')->orWhere('checked_at', '<=', $now->subHours(self::RECHECK_HOURS)))))
            ->orderBy('created_at')->get();

        foreach ($due as $domain) {
            $this->check($domain, $now);
            $count++;
        }

        $expired = SsoDomain::query()->whereNull('archived_at')->where('status', SsoDomain::FAILED)
            ->where('failed_at', '<=', $now->subDays(self::FAILED_KEEP_DAYS))->get();

        foreach ($expired as $domain) {
            $this->archive($domain, 'failed_expired');
            $count++;
        }

        return $count;
    }

    public function archive(SsoDomain $domain, ?string $reason = null): SsoDomain
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $reason) {
            $domain = SsoDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($domain->archived_at === null) {
                $domain->forceFill(['archived_at' => CarbonImmutable::now()])->save();
                $this->auditor->record('core.sso.domain_archive', $domain, ['domain' => $domain->domain, 'status' => $domain->status], array_filter(['archived' => true, 'reason' => $reason]));
            }

            return $domain;
        });
    }

    /** @param list<string>|null $records */
    private function prove(SsoDomain $domain, ?array $records, bool $found, CarbonImmutable $now): SsoDomain
    {
        if ($found) {
            try {
                return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $now) {
                    $domain = SsoDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
                    $domain->forceFill(['status' => SsoDomain::VERIFIED, 'verified_at' => $now, 'checked_at' => $now, 'failure' => null, 'missed_checks' => 0])->save();
                    $this->auditor->record('core.sso.domain_verify', $domain, ['status' => SsoDomain::PENDING], ['status' => SsoDomain::VERIFIED, 'domain' => $domain->domain]);

                    return $domain;
                });
            } catch (UniqueConstraintViolationException) {
                // Another tenant proved this domain first.
                return $this->fail($domain, 'claimed_elsewhere', $now);
            }
        }

        $expired = $domain->pending_since !== null
            && $domain->pending_since->lt($now->subDays((int) config('branding.domains.pending_days', 3)));
        $failure = $records === null ? 'lookup_failed' : 'record_missing';

        if ($expired) {
            return $this->fail($domain, $failure, $now);
        }

        $domain->forceFill(['checked_at' => $now, 'failure' => $failure])->save();

        return $domain;
    }

    /** @param list<string>|null $records */
    private function recheck(SsoDomain $domain, ?array $records, bool $found, CarbonImmutable $now): SsoDomain
    {
        if ($records === null) {
            $domain->forceFill(['checked_at' => $now])->save();

            return $domain;
        }

        if ($found) {
            $domain->forceFill(['checked_at' => $now, 'missed_checks' => 0, 'failure' => null])->save();

            return $domain;
        }

        $missed = $domain->missed_checks + 1;

        if ($missed < self::MISSES_ALLOWED) {
            $domain->forceFill(['checked_at' => $now, 'missed_checks' => $missed, 'failure' => 'record_missing'])->save();

            return $domain;
        }

        return $this->fail($domain, 'record_removed', $now, $missed);
    }

    private function fail(SsoDomain $domain, string $failure, CarbonImmutable $now, ?int $missed = null): SsoDomain
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $failure, $now, $missed) {
            $domain = SsoDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $before = $domain->status;
            $domain->forceFill(array_filter([
                'status' => SsoDomain::FAILED,
                'failure' => $failure,
                'checked_at' => $now,
                'failed_at' => $now,
                'missed_checks' => $missed,
            ], fn ($value) => $value !== null))->save();

            $this->auditor->record($before === SsoDomain::VERIFIED ? 'core.sso.domain_lost' : 'core.sso.domain_fail', $domain,
                ['status' => $before], ['status' => SsoDomain::FAILED, 'domain' => $domain->domain, 'failure' => $failure]);

            return $domain;
        });
    }
}
