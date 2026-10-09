<?php

namespace App\Core\Tenancy;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The tenants a scheduled command has work for, found before any tenant is
 * set (ADR 002). Each question is an owner-owned security-definer function
 * (migrations 2026_10_18_000100, 2026_10_18_000400, 2026_10_20_000100,
 * 2026_10_20_000200 and 2026_10_24_000100) called on the runtime connection: it
 * returns only tenant ids, so the scheduler and the workers never need the
 * owner's credentials. The work itself then runs in each tenant's context,
 * under row-level security.
 */
class DueTenants
{
    /** @return list<string> APR-05: reminders or escalations due at $at. */
    public function withDueApprovalTimers(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_due_approval_timers(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> AUTO-01: live rules of $kind ('schedule' or 'date') due at $at. */
    public function withDueAutomation(string $kind, CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_due_automation(?, ?::timestamptz)', [$kind, $at->toIso8601String()]);
    }

    /** @return list<string> AUTO-05: runs or webhook deliveries untouched since $staleBefore. */
    public function withStuckAutomation(CarbonInterface $staleBefore): array
    {
        return $this->ids('app_tenants_with_stuck_automation(?::timestamptz)', [$staleBefore->toIso8601String()]);
    }

    /** @return list<string> WF-09: stage reminders, overdue notices or escalations due at $at. */
    public function withDueStageTimers(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_due_stage_timers(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> M4: credit limit changes still pending whose flow ended before $before. */
    public function withUnsettledCreditChanges(CarbonInterface $before): array
    {
        return $this->ids('app_tenants_with_unsettled_credit_changes(?::timestamptz)', [$before->toIso8601String()]);
    }

    /** @return list<string> Payments: STK pushes or payouts past their timeout, manual codes due for a check, at $at. */
    public function withDuePaymentIntents(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_due_payment_intents(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> Fiscal: submissions due at $at, or left `sending` since before $staleBefore. */
    public function withDueFiscalSubmissions(CarbonInterface $at, CarbonInterface $staleBefore): array
    {
        return $this->ids('app_tenants_with_due_fiscal_submissions(?::timestamptz, ?::timestamptz)', [$at->toIso8601String(), $staleBefore->toIso8601String()]);
    }

    /** @return list<string> NOT-05: emails held for a digest. */
    public function withPendingDigests(): array
    {
        return $this->ids('app_tenants_with_pending_digests()');
    }

    /** @return list<string> BR-05: custom domains waiting for their DNS TXT check. */
    public function withPendingDomains(): array
    {
        return $this->ids('app_tenants_with_pending_domains()');
    }

    /** @return list<string> Every active tenant. */
    public function active(): array
    {
        return $this->ids('app_active_tenant_ids()');
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<string>
     */
    private function ids(string $call, array $bindings = []): array
    {
        $rows = DB::connection(TenantContext::CONNECTION)->select("select t.id from public.{$call} as t(id)", $bindings);

        return array_map(fn ($row) => (string) $row->id, $rows);
    }
}
