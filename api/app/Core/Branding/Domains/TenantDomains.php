<?php

namespace App\Core\Branding\Domains;

use App\Core\Audit\Auditor;
use App\Core\Branding\Models\TenantDomain;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BR-05: the tenant's custom domains.
 *
 * - add: a pending claim with a random token. Several tenants may claim the
 *   same host; the tenant publishes a TXT record `{txt_prefix}.{host}` with
 *   the value `{txt_value_prefix}{token}`, and the first claim proven wins
 *   (a host is unique among verified domains only). Another claim that later
 *   finds its record fails with `claimed_elsewhere`.
 * - check: a pending claim is verified when its record is there; still
 *   unproven after `branding.domains.pending_days` it fails. A verified
 *   domain is checked again (daily, `domains:verify`): when the record is
 *   missing on MISSES_ALLOWED checks in a row (failed lookups do not count),
 *   it drops to failed, which stops TLS, sign-in branding and the email
 *   sender at once; the tenant's domain managers are notified.
 * - failed claims are archived after FAILED_KEEP_DAYS.
 * - archive: the domain stops working and its host is free again.
 *
 * Every change is audited as `core.domain.*` (AUD-01). Checks run in the
 * tenant's context, on the runtime connection.
 */
class TenantDomains
{
    public const LOST = 'core.domain.lost';

    public const MISSES_ALLOWED = 3;

    public const FAILED_KEEP_DAYS = 7;

    public const RECHECK_HOURS = 20;

    public function __construct(
        private readonly DnsResolver $dns,
        private readonly Auditor $auditor,
        private readonly Notifier $notifier,
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
        // One open claim per host within a tenant.
        if (TenantDomain::query()->where('host', $host)->whereNull('archived_at')->exists()) {
            $message = __('branding.errors.domain_added');

            throw new ApiException(422, 'domain_added', $message, ['host' => [$message]]);
        }

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
    }

    /** Look the TXT record up now and update the domain. */
    public function check(TenantDomain $domain, ?CarbonImmutable $now = null): TenantDomain
    {
        $now ??= CarbonImmutable::now();

        if ($domain->archived_at !== null || $domain->status === TenantDomain::FAILED) {
            return $domain;
        }

        $records = $this->dns->txt(self::recordName($domain));
        $found = $records !== null && in_array(self::recordValue($domain), array_map('trim', $records), true);

        return $domain->status === TenantDomain::VERIFIED
            ? $this->recheck($domain, $records, $found, $now)
            : $this->prove($domain, $records, $found, $now);
    }

    /** "Check now": a failed claim is pending again, then checked. */
    public function retry(TenantDomain $domain): TenantDomain
    {
        if ($domain->status === TenantDomain::FAILED) {
            DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain) {
                $domain->forceFill(['status' => TenantDomain::PENDING, 'pending_since' => CarbonImmutable::now(), 'failed_at' => null, 'missed_checks' => 0])->save();
                $this->auditor->record('core.domain.retry', $domain, ['status' => TenantDomain::FAILED], ['status' => TenantDomain::PENDING]);
            });
        }

        return $this->check($domain);
    }

    /**
     * The scheduled work of the current tenant (domains:verify): check pending
     * claims, re-check verified domains not checked for RECHECK_HOURS, and
     * archive claims failed for FAILED_KEEP_DAYS.
     *
     * @return int domains looked at
     */
    public function runDue(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $count = 0;

        $due = TenantDomain::query()->whereNull('archived_at')
            ->where(fn ($q) => $q->where('status', TenantDomain::PENDING)
                ->orWhere(fn ($v) => $v->where('status', TenantDomain::VERIFIED)
                    ->where(fn ($c) => $c->whereNull('checked_at')->orWhere('checked_at', '<=', $now->subHours(self::RECHECK_HOURS)))))
            ->orderBy('created_at')->get();

        foreach ($due as $domain) {
            $this->check($domain, $now);
            $count++;
        }

        $expired = TenantDomain::query()->whereNull('archived_at')->where('status', TenantDomain::FAILED)
            ->where('failed_at', '<=', $now->subDays(self::FAILED_KEEP_DAYS))->get();

        foreach ($expired as $domain) {
            $this->archive($domain, 'failed_expired');
            $count++;
        }

        return $count;
    }

    public function archive(TenantDomain $domain, ?string $reason = null): TenantDomain
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $reason) {
            $domain = TenantDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($domain->archived_at === null) {
                $domain->forceFill(['archived_at' => CarbonImmutable::now()])->save();
                $this->auditor->record('core.domain.archive', $domain, ['host' => $domain->host, 'status' => $domain->status], array_filter(['archived' => true, 'reason' => $reason]));
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

    /** @param list<string>|null $records */
    private function prove(TenantDomain $domain, ?array $records, bool $found, CarbonImmutable $now): TenantDomain
    {
        if ($found) {
            try {
                return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $now) {
                    $domain = TenantDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
                    $domain->forceFill(['status' => TenantDomain::VERIFIED, 'verified_at' => $now, 'checked_at' => $now, 'failure' => null, 'missed_checks' => 0])->save();
                    $this->auditor->record('core.domain.verify', $domain, ['status' => TenantDomain::PENDING], ['status' => TenantDomain::VERIFIED, 'host' => $domain->host]);

                    return $domain;
                });
            } catch (UniqueConstraintViolationException) {
                // Another tenant proved this host first.
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
    private function recheck(TenantDomain $domain, ?array $records, bool $found, CarbonImmutable $now): TenantDomain
    {
        if ($records === null) {
            // A failed lookup says nothing about the record.
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

        $domain = $this->fail($domain, 'record_removed', $now, $missed);
        $this->notifyLost($domain);

        return $domain;
    }

    private function fail(TenantDomain $domain, string $failure, CarbonImmutable $now, ?int $missed = null): TenantDomain
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($domain, $failure, $now, $missed) {
            $domain = TenantDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();
            $before = $domain->status;
            $domain->forceFill(array_filter([
                'status' => TenantDomain::FAILED,
                'failure' => $failure,
                'checked_at' => $now,
                'failed_at' => $now,
                'missed_checks' => $missed,
            ], fn ($value) => $value !== null))->save();

            $this->auditor->record($before === TenantDomain::VERIFIED ? 'core.domain.lost' : 'core.domain.fail', $domain,
                ['status' => $before], ['status' => TenantDomain::FAILED, 'host' => $domain->host, 'failure' => $failure]);

            return $domain;
        });
    }

    /** BR-05: tell the tenant's domain managers that a verified domain stopped working. */
    private function notifyLost(TenantDomain $domain): void
    {
        $recipients = User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', RoleAssignment::query()->select('user_id'))
            ->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can('core.domain.manage', Scope::tenant()))
            ->modelKeys();

        if ($recipients === []) {
            return;
        }

        $this->notifier->send(new NotificationEvent(self::LOST, $recipients, ['host' => $domain->host], '/settings/domains'));
    }
}
