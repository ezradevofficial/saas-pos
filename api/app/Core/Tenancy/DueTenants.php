<?php

namespace App\Core\Tenancy;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The tenants a scheduled command has work for, found before any tenant is
 * set (ADR 002). Each question is an owner-owned security-definer function
 * (migrations 2026_10_18_000100, 2026_10_18_000400, 2026_10_20_000100,
 * 2026_10_20_000200, 2026_10_24_000100, 2026_10_25_000100, 2026_10_30_000600 and 2026_10_31_000100;
 * the modules' own: Accounting 2026_11_05_000100, Loyalty 2026_11_06_000200) called on the runtime connection: it
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

    /** @return list<string> BR-05: active tenants with domains to check, re-check (verified, unchecked for 20 hours) or archive (failed 7 days) at $at. */
    public function withDomainChecksDue(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_domain_checks_due(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> AUTH-11: active tenants with SSO email domains to check, re-check or archive at $at (as above). */
    public function withSsoDomainChecksDue(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_sso_domain_checks_due(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> SUB-03, SUB-05: subscriptions with a trial or period ending within 7 days of $at, past due or read-only. */
    public function withBillingDue(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_billing_due(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> SUB-07: switched-off modules, switched off before $before, not yet flagged for purge. */
    public function withModulesToPurge(CarbonInterface $before): array
    {
        return $this->ids('app_tenants_with_modules_to_purge(?::timestamptz)', [$before->toIso8601String()]);
    }

    /** SUB-03: the operator tenant (the seller of the platform), when one was set up (`platform:operator`). */
    public function operator(): ?string
    {
        return $this->ids('app_operator_tenant_id()')[0] ?? null;
    }

    /** @return list<string> EXP-02: full exports past their expiry at $at, builds running since before $runningBefore, or queued since before $queuedBefore. */
    public function withDueExports(CarbonInterface $at, CarbonInterface $runningBefore, CarbonInterface $queuedBefore): array
    {
        return $this->ids('app_tenants_with_due_exports(?::timestamptz, ?::timestamptz, ?::timestamptz)', [$at->toIso8601String(), $runningBefore->toIso8601String(), $queuedBefore->toIso8601String()]);
    }

    /** @return list<string> ONB-04: trials past their end at $at whose modules are not switched off yet. */
    public function withExpiredTrials(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_expired_trials(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> ONB-01: self sign-ups created before $before whose owner never verified. */
    public function pendingVerification(CarbonInterface $before): array
    {
        return $this->ids('app_tenants_pending_verification(?::timestamptz)', [$before->toIso8601String()]);
    }

    /** @return list<string> ONB-08: assisted tenants created before $before and not handed over yet. */
    public function awaitingHandover(CarbonInterface $before): array
    {
        return $this->ids('app_tenants_awaiting_handover(?::timestamptz)', [$before->toIso8601String()]);
    }

    /** ONB-08: the pending assisted tenant created for $contact (email or phone), if any. */
    public function assistedFor(string $contact): ?string
    {
        return $this->ids('app_assisted_tenant_for_contact(?)', [$contact])[0] ?? null;
    }

    /** @return list<string> LOY-03: Loyalty sales `failed` with a retry due at $at, or left `pending` since before $pendingBefore. */
    public function withLoyaltySalesToReprocess(CarbonInterface $at, CarbonInterface $pendingBefore): array
    {
        return $this->ids('app_tenants_with_loyalty_sales_to_reprocess(?::timestamptz, ?::timestamptz)', [$at->toIso8601String(), $pendingBefore->toIso8601String()]);
    }

    /** @return list<string> ACC-06: recurring journals due on $on (a calendar day). */
    public function withDueRecurringJournals(CarbonInterface $on): array
    {
        return $this->ids('app_tenants_with_due_recurring_journals(?::date)', [$on->toDateString()]);
    }

    /** @return list<string> CRM-27: an open CRM activity whose reminder is due at $at. */
    public function withDueCrmReminders(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_due_crm_reminders(?::timestamptz)', [$at->toIso8601String()]);
    }

    /** @return list<string> ECOM-14: an unpaid online order whose stock reservation expired at $at. */
    public function withExpiredEcommerceReservations(CarbonInterface $at): array
    {
        return $this->ids('app_tenants_with_expired_ecom_reservations(?::timestamptz)', [$at->toIso8601String()]);
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
