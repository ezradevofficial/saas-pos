<?php

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Http\Requests\ExtendTrialRequest;
use App\Core\Billing\Http\Requests\GraceOverrideRequest;
use App\Core\Billing\Http\Requests\MarkInvoicePaidRequest;
use App\Core\Billing\Http\Requests\PlatformBillingRequest;
use App\Core\Billing\Http\Requests\PlatformTenantsRequest;
use App\Core\Billing\Http\Requests\TenantActionRequest;
use App\Core\Billing\Http\Requests\UpdateBillingSettingsRequest;
use App\Core\Billing\Http\SubscriptionPresenter;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Operator\Accounts;
use App\Core\Billing\Operator\Operator;
use App\Core\Billing\Payments\BillingPayments;
use App\Core\Billing\PlatformSettings;
use App\Core\Onboarding\Assisted\AssistedOnboarding;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\Tenants\PlatformTenants;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Platform console (SUB-02..SUB-06, ONB-04, ADM-01): customer tenants from
 * the operator's accounts (search), a tenant's subscription, usage, health
 * and invoices, suspend, reactivate and trial extension (PlatformTenants),
 * grace override, bank transfers marked paid, and the billing settings. Tenant data is read and changed only inside the tenant's own
 * context (or the operator's); every action is audited (PlatformAuditor).
 */
class PlatformTenantController
{
    public function __construct(
        private readonly Operator $operator,
        private readonly TenantContext $tenants,
        private readonly PlatformAuditor $auditor,
        private readonly Accounts $accounts,
        private readonly PlatformTenants $platformTenants,
    ) {}

    /** ADM-01a: search the customer tenants (the operator's accounts). */
    public function index(PlatformTenantsRequest $request): JsonResponse
    {
        $accounts = $this->platformTenants->search($request->validated());

        return response()->json(['data' => $accounts->map(fn (OperatorAccount $a) => self::account($a))->all()]);
    }

    public function show(PlatformTenantsRequest $request, string $tenant): JsonResponse
    {
        return $this->detail($tenant);
    }

    /** ADM-01b: plan, modules, usage, health, subscription, billing and invoices. */
    private function detail(string $tenant): JsonResponse
    {
        $account = $this->platformTenants->account404($tenant);
        [$subscription, $assisted] = $this->tenants->run($tenant, fn () => [Subscription::query()->first(), app(AssistedOnboarding::class)->handoverState()]);
        $invoices = $this->operator->run(fn () => OperatorInvoice::query()->where('customer_tenant_id', $tenant)->orderByDesc('issued_at')->limit(100)->get());

        return response()->json(['data' => [
            'account' => self::account($account),
            'subscription' => $subscription === null ? null : SubscriptionPresenter::subscription($subscription, ['date' => $subscription->nextDueAt()?->toDateString(), 'amount_minor' => null]),
            'grace_days' => $subscription?->grace_days,
            // ONB-08: staff may resend the Owner invitation until handover.
            'assisted' => $assisted,
            ...$this->platformTenants->overview($tenant),
            'invoices' => $invoices->map(fn (OperatorInvoice $i) => self::invoice($i))->all(),
        ]]);
    }

    /** ONB-04, ADM-01c: extend a trial, with a reason. */
    public function extendTrial(ExtendTrialRequest $request, string $tenant): JsonResponse
    {
        $this->platformTenants->extendTrial($tenant, CarbonImmutable::parse($request->validated('trial_ends_at'))->utc(), (string) $request->validated('reason'), $request->staff());

        return $this->detail($tenant);
    }

    /** ADM-01c: suspend a tenant, with a reason. */
    public function suspend(TenantActionRequest $request, string $tenant): JsonResponse
    {
        $this->platformTenants->suspend($tenant, (string) $request->validated('reason'), $request->staff());

        return $this->detail($tenant);
    }

    /** ADM-01c: reactivate a suspended tenant, with a reason. */
    public function reactivate(TenantActionRequest $request, string $tenant): JsonResponse
    {
        $this->platformTenants->reactivate($tenant, (string) $request->validated('reason'), $request->staff());

        return $this->detail($tenant);
    }

    /** SUB-05: the tenant's own grace period, or null for the platform's. */
    public function grace(GraceOverrideRequest $request, string $tenant): JsonResponse
    {
        $this->account404($tenant);
        $days = $request->validated('grace_days');

        [$subscription, $before] = $this->tenants->run($tenant, function () use ($days) {
            $result = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($days) {
                $subscription = Subscription::query()->lockForUpdate()->firstOrFail();
                $before = $subscription->grace_days;
                $subscription->fill(['grace_days' => $days])->save();

                return [$subscription, $before];
            });
            $this->accounts->sync($result[0]);

            return $result;
        });

        $this->auditor->record('platform.subscription.grace_override', $subscription, ['tenant_id' => $tenant, 'grace_days' => $before], ['tenant_id' => $tenant, 'grace_days' => $days]);

        return $this->detail($tenant);
    }

    /** SUB-04: a bank transfer received (Enterprise). */
    public function markPaid(MarkInvoicePaidRequest $request, string $operatorInvoice, BillingPayments $payments): JsonResponse
    {
        $invoice = $this->operator->id() === null ? null : $this->operator->run(fn () => OperatorInvoice::query()->find($operatorInvoice));

        if ($invoice === null) {
            abort(404);
        }

        $paid = $payments->markPaid($invoice, 'bank_transfer', (string) $request->validated('reference'));
        $this->auditor->record('platform.invoice.mark_paid', $paid, ['status' => $invoice->status], ['status' => $paid->status, 'paid_via' => 'bank_transfer', 'reference' => $request->validated('reference'), 'tenant_id' => $paid->customer_tenant_id]);

        return response()->json(['data' => self::invoice($paid)]);
    }

    public function settings(PlatformBillingRequest $request, PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->all()]);
    }

    public function updateSettings(UpdateBillingSettingsRequest $request, PlatformSettings $settings): JsonResponse
    {
        return response()->json(['data' => $settings->update($request->validated(), $request->staff())]);
    }

    private function account404(string $tenant): OperatorAccount
    {
        return $this->platformTenants->account404($tenant);
    }

    private static function account(OperatorAccount $account): array
    {
        return [
            'tenant_id' => $account->customer_tenant_id,
            'name' => $account->name,
            'country' => $account->country,
            'slug' => $account->slug,
            'plan' => $account->plan,
            'bundle' => $account->bundle,
            'modules' => $account->modules ?? [],
            'deal' => $account->deal,
            'cycle' => $account->cycle,
            'status' => $account->status,
            'mrr' => ['amount_minor' => $account->mrr_minor, 'currency' => $account->currency],
            'trial_ends_at' => $account->trial_ends_at?->toIso8601String(),
            'current_period_end' => $account->current_period_end?->toDateString(),
            'grace_days' => $account->grace_days,
            'purge_due' => $account->purge_due ?? [],
            'alerts' => $account->alerts ?? [],
        ];
    }

    private static function invoice(OperatorInvoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'kind' => $invoice->kind,
            'status' => $invoice->status,
            'total' => ['amount_minor' => $invoice->total_minor, 'currency' => $invoice->currency],
            'tax' => ['amount_minor' => $invoice->tax_minor, 'currency' => $invoice->currency],
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'due_at' => $invoice->due_at->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'paid_via' => $invoice->paid_via,
            'payment_reference' => $invoice->payment_reference,
            'overpayments' => $invoice->overpayments ?? [],
        ];
    }
}
