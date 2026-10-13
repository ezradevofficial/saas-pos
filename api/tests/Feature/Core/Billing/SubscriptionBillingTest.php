<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Audit\AuditEntry;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Billing\Models\BillingInvoice;
use App\Core\Billing\Models\BillingPayment;
use App\Core\Billing\Models\BillingPaymentMethod;
use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Payments\BillingPayments;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Fiscal\Models\FiscalSubmission;
use App\Core\Limits\PlanAllowances;
use App\Core\Limits\UsageLimits;
use App\Core\Limits\UsageOverage;
use App\Core\Limits\UsageOverageLine;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRates;
use App\Core\Payments\Models\PaymentIntent;
use App\Core\Platform\PlatformDatabase;
use App\Core\Rbac\Models\TenantModule;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\Models\Device;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * SUB-02..SUB-04, SUB-06, SUB-07, TEN-01, AUD-01: subscriptions from the
 * trial, pro-rated upgrades, downgrades at the period end, invoices issued
 * by the operator (fiscal queue, the tenant's copy), payments, coupons and
 * the module registry following the plan.
 */
class SubscriptionBillingTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    public function test_a_trial_starts_with_the_plan_and_its_modules_on(): void
    {
        $subscription = $this->subscription();

        $this->assertSame([Subscription::TRIAL, 'KES', 390000], [$subscription->status, $subscription->currency, $subscription->period_amount_minor]);
        $this->assertSame('2026-11-15', $subscription->trial_ends_at->toDateString());
        $this->assertNull($subscription->current_period_start);
        $this->assertArrayHasKey('bundle:starter', $subscription->price_ids);
        $this->assertTrue($this->inTenant(fn () => app(ModuleRegistry::class)->isActive('pos')));

        $this->getJson('/api/v1/subscription/status', $this->headersFor())->assertOk()
            ->assertJsonPath('data.status', 'trial')->assertJsonPath('data.trial_days_left', 14);
        $this->getJson('/api/v1/subscription', $this->headersFor())->assertOk()
            ->assertJsonPath('data.next_invoice.date', '2026-11-15')
            ->assertJsonPath('data.next_invoice.amount', ['amount_minor' => 390000, 'currency' => 'KES']);

        // The operator's record of the customer, for the platform console.
        $account = $this->inOperator(fn () => OperatorAccount::query()->where('customer_tenant_id', $this->owner->tenant_id)->sole());
        $this->assertSame(['starter', 'trial', 0], [$account->plan, $account->status, $account->mrr_minor]);
    }

    public function test_subscribing_from_the_trial_issues_the_first_invoice_from_the_operator_and_paying_it_makes_it_active(): void
    {
        // A test figure, never a real rate: the operator company's billing tax code.
        $this->inOperator(function () {
            $code = TaxCode::query()->where('company_id', Company::query()->where('country', 'KE')->value('id'))->where('code', 'VAT_STD')->sole();
            app(TaxRates::class)->add($code, '12.5', CarbonImmutable::parse('2026-11-01'));
        });

        $response = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated();
        $invoice = $this->inTenant(fn () => BillingInvoice::query()->findOrFail($response->json('data.id')));

        // The tenant's copy: dates, number, total, the seller.
        $this->assertSame(['first', 'open', 390000, '2026-11-01', '2026-12-01', '2026-11-15'], [
            $invoice->kind, $invoice->status, $invoice->total_minor, $invoice->period_start->toDateString(), $invoice->period_end->toDateString(), $invoice->due_at->toDateString(),
        ]);
        $this->assertMatchesRegularExpression('/^INV-2026-0+1$/', $invoice->number);
        // Tax included in KES 3,900.00 at 12.5 %: 433.33.
        $this->assertSame(43333, $invoice->tax_minor);

        // The operator's invoice: a document of its KE company, handed to its fiscal queue.
        $operatorInvoice = $this->operatorInvoice($invoice->operator_invoice_id);
        $this->assertSame([$this->owner->tenant_id, $invoice->number, 390000, '12.5000'], [$operatorInvoice->customer_tenant_id, $operatorInvoice->number, $operatorInvoice->total_minor, $operatorInvoice->tax_rate]);
        $submission = $this->inOperator(fn () => FiscalSubmission::query()->where('source', 'billing')->where('document_id', $operatorInvoice->id)->sole());
        $this->assertSame([$operatorInvoice->company_id, 'sale', 390000, 43333], [$submission->company_id, $submission->document_type, $submission->payload['totals']['total_minor'], $submission->payload['totals']['tax_minor']]);

        // TEN-01: the tenant never sees the operator's rows.
        $this->inTenant(function () {
            $this->assertSame(0, OperatorInvoice::query()->count());
            $this->assertSame(0, FiscalSubmission::query()->count());
        });

        // Paid with the fake M-Pesa: the push is pending, then checked and paid.
        $this->payWithMpesa($invoice->id)->assertJsonPath('data.status', 'succeeded');

        $this->assertSame('paid', $this->operatorInvoice($operatorInvoice->id)->status);
        $this->assertSame('paid', $this->inTenant(fn () => $invoice->fresh()->status));
        $subscription = $this->subscription();
        $this->assertSame([Subscription::ACTIVE, '2026-11-01', '2026-12-01'], [$subscription->status, $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString()]);
        $this->assertContains('core.subscription.payment_received', $this->ownerNotices());
        $intent = $this->inOperator(fn () => PaymentIntent::query()->sole());
        $this->assertSame(['billing.invoice', $operatorInvoice->id, 'fake', null], [$intent->reference_type, $intent->reference, $intent->driver, $intent->created_by]);

        // AUD-01: the subscription's changes are audited in the tenant's chain.
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.subscription.update')->exists()));
    }

    public function test_an_upgrade_is_charged_pro_rata_for_the_days_left_with_exact_amounts(): void
    {
        $this->activate();
        $this->travelToDay('2026-11-21 10:00:00');
        $plan = [...self::STARTER, 'modules' => ['inventory', 'loyalty', 'payroll', 'pos'], 'quantities' => ['outlet' => 1, 'employee' => 20]];

        // Payroll for 20 employees is KES 3,000 a month; 10 of 30 days are left: KES 1,000.00.
        $quote = $this->postJson('/api/v1/subscription/quote', $plan, $this->headersFor())->assertOk();
        $quote->assertJsonPath('data.kind', 'upgrade')->assertJsonPath('data.effective', 'now')
            ->assertJsonPath('data.charge_now', 100000)->assertJsonPath('data.period_days', 30)->assertJsonPath('data.remaining_days', 10)
            ->assertJsonPath('data.current.total', 390000)->assertJsonPath('data.new.total', 690000);

        // A confirm with an amount the quote no longer gives is refused with the fresh quote.
        $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 90000], $this->headersFor())
            ->assertStatus(409)->assertJsonPath('code', 'quote_changed')->assertJsonPath('quote.charge_now', 100000);

        $change = $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 100000], $this->headersFor())->assertOk();
        $change->assertJsonPath('data.invoice.kind', 'upgrade')->assertJsonPath('data.invoice.total.amount_minor', 100000);

        $subscription = $this->subscription();
        $this->assertSame([690000, ['inventory', 'loyalty', 'payroll', 'pos']], [$subscription->period_amount_minor, $subscription->modules]);
        $upgrade = $this->invoices()[1];
        $this->assertSame([100000, '2026-11-21', '2026-12-01'], [$upgrade->total_minor, $upgrade->period_start->toDateString(), $upgrade->period_end->toDateString()]);
        $this->assertEquals([['type' => 'proration', 'quantity' => 10, 'period_days' => 30, 'amount' => 100000]], array_map(fn ($l) => array_intersect_key($l, array_flip(['type', 'quantity', 'period_days', 'amount'])), $upgrade->lines));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.subscription.change')->exists()));
    }

    public function test_a_downgrade_takes_effect_at_the_period_end_and_renews_at_the_lower_price(): void
    {
        $this->activate();
        $big = [...self::STARTER, 'modules' => ['inventory', 'loyalty', 'payroll', 'pos'], 'quantities' => ['outlet' => 1, 'employee' => 20]];
        $charge = $this->postJson('/api/v1/subscription/quote', $big, $this->headersFor())->json('data.charge_now');
        $this->postJson('/api/v1/subscription/change', [...$big, 'expected_charge' => $charge], $this->headersFor())->assertOk();

        $quote = $this->postJson('/api/v1/subscription/quote', self::STARTER, $this->headersFor())->assertOk();
        $quote->assertJsonPath('data.kind', 'scheduled')->assertJsonPath('data.effective', 'period_end')
            ->assertJsonPath('data.effective_on', '2026-12-01')->assertJsonPath('data.charge_now', 0);
        $this->postJson('/api/v1/subscription/change', [...self::STARTER, 'expected_charge' => 0], $this->headersFor())->assertOk()
            ->assertJsonPath('data.invoice', null)->assertJsonPath('data.subscription.pending_change.effective_on', '2026-12-01');

        // Nothing changes before the period end.
        $this->assertContains('payroll', $this->subscription()->modules);

        $this->cycle('2026-12-01 01:00:00');
        $subscription = $this->subscription();
        $this->assertSame([['inventory', 'loyalty', 'pos'], null, 390000, '2026-12-01', '2027-01-01'], [
            $subscription->modules, $subscription->pending_change, $subscription->period_amount_minor,
            $subscription->current_period_start->toDateString(), $subscription->current_period_end->toDateString(),
        ]);
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $this->assertSame([390000, '2026-12-01'], [$renewal->total_minor, $renewal->period_start->toDateString()]);
    }

    public function test_usage_overage_from_metering_is_priced_on_the_renewal_once(): void
    {
        // LIM-05: a test double of metering: one extra POS device on 10 of the period's 30 days.
        $this->app->instance(UsageOverage::class, new class implements UsageOverage
        {
            public array $asked = [];

            public function overageFor(string $tenantId, CarbonImmutable $periodStart, CarbonImmutable $periodEnd): array
            {
                $this->asked[] = [$periodStart->toDateString(), $periodEnd->toDateString()];

                return [new UsageOverageLine('device', 1, 10, 30), new UsageOverageLine('user', 3, 30, 30)];
            }
        });
        $this->activate();

        $this->cycle('2026-12-01 06:00:00');
        $renewal = collect($this->invoices())->firstWhere('kind', 'renewal');
        $overage = collect($renewal->lines)->firstWhere('type', 'overage');
        // KES 500 a device a month, 10 of 30 days: KES 166.67; users are not sold, never charged.
        $this->assertSame(['pos_device', 1, 50000, 16667, 10, 30], [$overage['key'], $overage['quantity'], $overage['unit_amount'], $overage['amount'], $overage['days'], $overage['period_days']]);
        $this->assertSame(390000 + 16667, $renewal->total_minor);
        $this->assertCount(1, array_filter($renewal->lines, fn ($l) => $l['type'] === 'overage'));
        $this->assertSame('2026-12-01', $this->subscription()->overage_billed_through->toDateString());
        $this->assertSame([['2026-11-01', '2026-12-01']], app(UsageOverage::class)->asked);
    }

    public function test_limits_follow_the_subscriptions_plan(): void
    {
        $allowances = app(PlanAllowances::class)->for($this->owner->tenant_id);
        // Starter at one outlet: 1 outlet and the 2 POS devices it includes; users unlimited.
        $this->assertSame([1, 2, null], [$allowances->of('outlet'), $allowances->of('device'), $allowances->of('user')]);
    }

    public function test_an_upgrade_unblocks_adding_a_device_at_once(): void
    {
        // Starter at one outlet allows the 2 POS devices it includes; 3 are active.
        $this->inTenant(function () {
            foreach (['Till 1', 'Till 2', 'Till 3'] as $name) {
                Device::create(['location_id' => $this->locationA->id, 'name' => $name])->forceFill(['status' => Device::STATUS_ACTIVE, 'paired_at' => now()])->save();
            }
            app(UsageLimits::class)->meter(CarbonImmutable::now());
        });
        $this->travelToDay('2026-11-09 06:00:00');
        $this->inTenant(fn () => app(UsageLimits::class)->meter(CarbonImmutable::now()));
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertStatus(402)->assertJsonPath('code', 'usage_limit_reached');

        // Four devices on the plan (during the trial it applies at once): adding works right away.
        $plan = [...self::STARTER, 'quantities' => ['device' => 4, 'outlet' => 1]];
        $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 0], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/locations/{$this->locationA->id}/devices", ['name' => 'Till 4'], $this->headersFor())->assertCreated();
    }

    public function test_owners_hold_the_subscription_permissions(): void
    {
        $this->assertTrue($this->inTenant(fn () => $this->owner->fresh()->can('core.subscription.view', Scope::tenant())));
        $this->assertTrue($this->inTenant(fn () => $this->owner->fresh()->can('core.subscription.manage', Scope::tenant())));
    }

    public function test_switching_a_module_off_hides_it_and_keeps_its_data_and_subscribing_again_restores_it(): void
    {
        // Payroll is not a registered module yet; the POS is. During the trial a change applies at once.
        $plan = ['modules' => ['inventory'], 'quantities' => ['outlet' => 1], 'cycle' => 'monthly', 'bundle' => null];
        $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 0], $this->headersFor())->assertOk()->assertJsonPath('data.quote.kind', 'trial');

        $module = $this->inTenant(fn () => TenantModule::query()->where('module', 'pos')->sole());
        $this->assertSame(['inactive', '2026-11-01'], [$module->status, $module->switched_off_at->toDateString()]);
        $this->getJson('/api/v1/pos/sales', $this->headersFor())->assertStatus(403)->assertJsonPath('code', 'module_inactive');

        // SUB-07: a year later the data is reported for purge; nothing is deleted.
        $this->travelToDay('2027-11-02');
        $this->artisan('billing:purge-report')->assertSuccessful();
        $this->assertNotNull($this->inTenant(fn () => TenantModule::query()->where('module', 'pos')->value('purge_flagged_at')));
        $this->assertSame([['module' => 'pos']], array_map(fn ($r) => ['module' => $r['module']], $this->inOperator(fn () => OperatorAccount::query()->sole()->purge_due)));

        $this->travelToDay('2026-11-03');
        $this->postJson('/api/v1/subscription/change', [...self::STARTER, 'expected_charge' => 0], $this->headersFor())->assertOk();
        $module = $this->inTenant(fn () => TenantModule::query()->where('module', 'pos')->sole());
        $this->assertSame(['active', null, null], [$module->status, $module->switched_off_at, $module->purge_flagged_at]);
        $this->getJson('/api/v1/pos/sales', $this->headersFor())->assertOk();
    }

    public function test_coupon_redemptions_are_counted_against_their_limit(): void
    {
        $coupon = new CatalogueCoupon(['code' => 'LAUNCH-2026', 'kind' => 'percent', 'percent' => 10, 'max_redemptions' => 1]);
        $coupon->save();
        $plan = [...self::STARTER, 'coupon' => 'LAUNCH-2026'];

        $this->postJson('/api/v1/subscription/quote', $plan, $this->headersFor())->assertOk()
            ->assertJsonPath('data.new.coupon_discount', 39000)->assertJsonPath('data.new.total', 351000);
        $this->postJson('/api/v1/subscription/change', [...$plan, 'expected_charge' => 0], $this->headersFor())->assertOk();
        $this->assertSame(1, (int) PlatformDatabase::connection()->table('catalogue_coupons')->where('code', 'LAUNCH-2026')->value('redemptions'));
        $this->assertSame([351000, 'LAUNCH-2026'], [$this->subscription()->period_amount_minor, $this->subscription()->coupon_code]);

        // A second coupon is refused; the used-up coupon is refused to another tenant.
        $this->postJson('/api/v1/subscription/quote', $plan, $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'coupon_already_applied');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $this->postJson('/api/v1/subscription/quote', $plan, $this->headersFor($other['user']))->assertStatus(422)->assertJsonPath('code', 'coupon_invalid');
        $this->assertSame(1, (int) PlatformDatabase::connection()->table('catalogue_coupons')->where('code', 'LAUNCH-2026')->value('redemptions'));
    }

    public function test_another_tenants_invoices_payments_and_methods_are_not_found(): void
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $theirs = $this->headersFor($other['user']);

        $this->getJson("/api/v1/subscription/invoices/{$invoice}", $theirs)->assertNotFound();
        $this->getJson("/api/v1/subscription/invoices/{$invoice}/pdf", $theirs)->assertNotFound();
        $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'mpesa_ke', 'phone' => '0712345678'], $theirs)->assertNotFound();
        $this->getJson('/api/v1/subscription/invoices', $theirs)->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $this->asTenant($other['user']->tenant_id, fn () => BillingPayment::query()->count()));

        // The tenant's own PDF: black on white from the A4 invoice template.
        $pdf = $this->get("/api/v1/subscription/invoices/{$invoice}/pdf", $this->headersFor())->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_only_the_owner_changes_the_plan_and_only_managers_of_the_subscription_pay(): void
    {
        $cashier = $this->userWith('cashier', Scope::tenant());
        $admin = $this->userWith('admin', Scope::tenant());
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');

        $this->getJson('/api/v1/subscription', $this->headersFor($cashier))->assertForbidden();
        $this->postJson('/api/v1/subscription/quote', self::STARTER, $this->headersFor($cashier))->assertForbidden();
        $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'mpesa_ke', 'phone' => '0712345678'], $this->headersFor($cashier))->assertForbidden();
        // Everyone sees the banner's status.
        $this->getJson('/api/v1/subscription/status', $this->headersFor($cashier))->assertOk()->assertJsonPath('data.status', 'trial');

        // An Admin holds core.* (sees, pays) but is not the Owner: no plan changes.
        $this->getJson('/api/v1/subscription', $this->headersFor($admin))->assertOk();
        $this->postJson('/api/v1/subscription/quote', self::STARTER, $this->headersFor($admin))->assertForbidden();
    }

    public function test_saved_wallets_renew_automatically_and_cards_are_kept_as_tokens(): void
    {
        $wallet = $this->postJson('/api/v1/subscription/payment-methods', ['kind' => 'mobile_money', 'provider' => 'mpesa_ke', 'phone' => '0712 345 678'], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.is_default', true)->assertJsonPath('data.label', '2547•••••678');
        $this->assertNull($wallet->json('data.phone'));

        $setup = $this->postJson('/api/v1/subscription/payment-methods', ['kind' => 'card'], $this->headersFor())->assertStatus(202);
        parse_str((string) parse_url($setup->json('data.redirect_url'), PHP_URL_QUERY), $query);
        $card = $this->postJson('/api/v1/subscription/payment-methods/card/complete', ['reference' => $query['card_setup']], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.label', 'Card •••• 4242')->assertJsonPath('data.is_default', false);
        $this->postJson('/api/v1/subscription/payment-methods/card/complete', ['reference' => $query['card_setup']], $this->headersFor())->assertStatus(422);
        $this->assertStringStartsWith('fake_tok_', $this->inTenant(fn () => BillingPaymentMethod::query()->findOrFail($card->json('data.id'))->token));
        $this->postJson('/api/v1/subscription/payment-methods/'.$card->json('data.id').'/default', [], $this->headersFor())->assertOk()->assertJsonPath('data.is_default', true);

        // At the trial end the first invoice is issued and the saved card is charged at once.
        $this->cycle('2026-11-15 09:00:00');
        $invoice = $this->invoices()[0];
        $this->assertSame(['first', 'paid'], [$invoice->kind, $invoice->status]);
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
        $this->assertSame('checkout', $this->inOperator(fn () => PaymentIntent::query()->sole()->mode));
    }

    public function test_staff_mark_a_bank_transfer_paid_and_the_tenant_is_active_at_once(): void
    {
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $operatorInvoiceId = $this->inTenant(fn () => BillingInvoice::query()->findOrFail($invoice)->operator_invoice_id);

        $payments = app(BillingPayments::class);
        $payments->markPaid($this->operatorInvoice($operatorInvoiceId), 'bank_transfer', 'BANK-REF-77');

        $this->assertSame(['paid', 'bank_transfer', 'BANK-REF-77'], [$this->operatorInvoice($operatorInvoiceId)->status, $this->operatorInvoice($operatorInvoiceId)->paid_via, $this->operatorInvoice($operatorInvoiceId)->payment_reference]);
        $this->assertSame(Subscription::ACTIVE, $this->subscription()->status);
        app(TenantContext::class)->set(null);
        $this->assertSame(0, (int) DB::table('billing_invoices')->count(), 'outside any tenant the copies are invisible');
    }
}
