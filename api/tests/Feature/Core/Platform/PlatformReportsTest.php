<?php

namespace Tests\Feature\Core\Platform;

use App\Core\Billing\Models\OperatorAccount;
use App\Core\Billing\Models\OperatorAccountEvent;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRates;
use App\Core\Platform\Models\PlatformUser;
use App\Core\Platform\PlatformDatabase;
use App\Core\Platform\Reports\Http\PlatformReportsController;
use App\Core\Platform\Reports\PlatformReports;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\CreatesPlatformStaff;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * ADM-06 (docs/modules/platform-admin.md): MRR, trials started and
 * converted, churn, revenue by country, module and plan, and usage totals,
 * from the operator's data on seeded tenants; CSV downloads; permissions.
 */
class PlatformReportsTest extends TestCase
{
    use BuildsBilling, CreatesPlatformStaff, RefreshTenantDatabase, ResetsPlatformTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();

        // A test figure, never a real rate: the operator's KE billing tax code at 12.5% from
        // today, so the revenue figures do not move with the country pack's researched VAT (CP-01).
        $this->inOperator(function () {
            $code = TaxCode::query()->where('company_id', Company::query()->where('country', 'KE')->value('id'))->where('code', 'VAT_STD')->sole();
            app(TaxRates::class)->add($code, '12.5', CarbonImmutable::parse('2026-11-01'));
        });
    }

    /** A KE tenant on Starter that subscribes and pays its first invoice. */
    private function payingTenant(): array
    {
        $other = $this->otherTenant();
        $headers = $this->headersFor($other['user']);
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $headers)->assertCreated()->json('data.id');
        $payment = $this->postJson("/api/v1/subscription/invoices/{$invoice}/pay", ['provider' => 'mpesa_ke', 'phone' => '0712345679'], $headers)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/subscription/payments/{$payment}", $headers)->assertOk()->assertJsonPath('data.status', 'succeeded');

        return $other;
    }

    public function test_reports_add_up_the_seeded_accounts_events_and_invoices(): void
    {
        // A: converted and paying. B: a CD trial. C: converted, then suspended (churn).
        $this->activate();
        $cd = $this->otherTenant();
        $this->asTenant($cd['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('CD', ['modules' => ['crm'], 'quantities' => ['company' => 1], 'cycle' => 'monthly']));
        $churner = $this->payingTenant();
        $this->postJson("/api/v1/platform/tenants/{$churner['user']->tenant_id}/suspend", ['reason' => 'Chargeback fraud under review'], $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT)))->assertOk();
        $this->artisan('usage:meter')->assertSuccessful();

        $finance = $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE));
        $data = $this->getJson('/api/v1/platform/reports/summary?from=2026-11-01&to=2026-11-30', $finance)->assertOk()->json('data');

        // MRR now: A only (C left), KES 3,900.00 on Starter in Kenya, net of tax as revenue is:
        // less 433.33 at the pinned 12.5%, split as the invoice splits it.
        $this->assertSame(1, $data['mrr']['accounts']);
        $this->assertSame([['key' => 'all', 'currency' => 'KES', 'accounts' => 1, 'amount_minor' => 346667]], $data['mrr']['by_currency']);
        $this->assertSame([['key' => 'KE', 'currency' => 'KES', 'accounts' => 1, 'amount_minor' => 346667]], $data['mrr']['by_country']);
        $this->assertSame([['key' => 'starter', 'currency' => 'KES', 'accounts' => 1, 'amount_minor' => 346667]], $data['mrr']['by_plan']);
        $this->assertSame([['key' => 'starter', 'currency' => 'KES', 'amount_minor' => 346667]], $data['mrr']['by_module']);

        // Trials: three started, two converted.
        $this->assertSame(['started' => 3, 'converted' => 2, 'conversion_percent' => '66.7'], array_intersect_key($data['trials'], array_flip(['started', 'converted', 'conversion_percent'])));
        $this->assertSame([['country' => 'CD', 'started' => 1, 'converted' => 0], ['country' => 'KE', 'started' => 2, 'converted' => 2]], $data['trials']['by_country']);

        // Churn: C, with the MRR it paid (net of tax); one of the two accounts paying at the start of the range.
        $this->assertSame(1, $data['churn']['churned']);
        $this->assertSame([['currency' => 'KES', 'accounts' => 1, 'amount_minor' => 346667]], $data['churn']['mrr_lost']);

        // Revenue: two first invoices paid in November, net of tax: KES 3,900.00 less 433.33 at 12.5%, twice.
        $this->assertSame(2, $data['revenue']['invoices']);
        $this->assertSame([['key' => 'all', 'currency' => 'KES', 'amount_minor' => 693334]], $data['revenue']['by_currency']);
        $this->assertSame([['key' => 'KE', 'currency' => 'KES', 'amount_minor' => 693334]], $data['revenue']['by_country']);
        $this->assertSame([['key' => 'starter', 'currency' => 'KES', 'amount_minor' => 693334]], $data['revenue']['by_plan']);
        $this->assertSame([['key' => 'starter', 'currency' => 'KES', 'amount_minor' => 693334]], $data['revenue']['by_module']);

        // Usage: every metered tenant, totals only.
        $users = collect($data['usage'])->firstWhere('unit', 'user');
        $this->assertGreaterThanOrEqual(3, $users['tenants']);
        $this->assertArrayNotHasKey('tenant_id', $users);

        // Outside the range: nothing.
        $empty = $this->getJson('/api/v1/platform/reports/summary?from=2026-12-01&to=2026-12-31', $finance)->assertOk()->json('data');
        $this->assertSame([0, 0, 0], [$empty['trials']['started'], $empty['churn']['churned'], $empty['revenue']['invoices']]);
    }

    /**
     * ADM-06, SUB-03: a trial converts when its first invoice is paid, though a subscribed
     * trial stays a trial until it ends: once (not again when it turns active), never for an
     * unpaid subscription; and being suspended then is churn with its MRR.
     */
    public function test_a_subscribed_trial_converts_when_its_first_invoice_is_paid_once_and_counts_for_churn(): void
    {
        $range = '?from=2026-11-01&to=2026-11-30';
        $finance = $this->staff(PlatformUser::ROLE_FINANCE);
        $support = $this->staff(PlatformUser::ROLE_SUPPORT);
        // Staff sessions time out as the clock moves: a fresh token for each call.
        $report = fn (string $key) => $this->getJson("/api/v1/platform/reports/summary{$range}", $this->staffBearer($finance))->assertOk()->json("data.{$key}");
        $trials = fn () => $report('trials');
        $converted = fn () => $this->inOperator(fn () => OperatorAccountEvent::query()->where('customer_tenant_id', $this->owner->tenant_id)->where('kind', 'converted')->count());

        // Subscribed but unpaid: no conversion.
        $invoice = $this->postJson('/api/v1/subscription/subscribe', [], $this->headersFor())->assertCreated()->json('data.id');
        $this->assertSame(0, $converted());

        // Paid, the trial still running: converted at once.
        $this->travelToDay('2026-11-03 10:00:00');
        $this->payWithMpesa($invoice);
        $this->assertSame('trial', $this->subscription()->status);
        $this->assertSame(1, $converted());
        $this->assertSame(['started' => 1, 'converted' => 1, 'conversion_percent' => '100.0'], array_intersect_key($trials(), array_flip(['started', 'converted', 'conversion_percent'])));

        // The trial ends and it turns active: not counted twice.
        $this->cycle('2026-11-15 09:00:00');
        $this->assertSame('active', $this->subscription()->status);
        $this->assertSame(1, $converted());

        // A second tenant pays during its trial, then is suspended: churn, with the MRR it paid.
        $other = $this->otherTenant();
        $headers = $this->headersFor($other['user']);
        $this->asTenant($other['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $due = $this->postJson('/api/v1/subscription/subscribe', [], $headers)->assertCreated()->json('data.id');
        $payment = $this->postJson("/api/v1/subscription/invoices/{$due}/pay", ['provider' => 'mpesa_ke', 'phone' => '0712345679'], $headers)->assertCreated()->json('data.id');
        $this->getJson("/api/v1/subscription/payments/{$payment}", $headers)->assertOk()->assertJsonPath('data.status', 'succeeded');
        $this->assertSame(2, $trials()['converted']);

        $this->postJson("/api/v1/platform/tenants/{$other['user']->tenant_id}/suspend", ['reason' => 'Chargeback fraud under review'], $this->staffBearer($support))->assertOk();
        $churn = $report('churn');
        $this->assertSame(1, $churn['churned']);
        $this->assertSame([['currency' => 'KES', 'accounts' => 1, 'amount_minor' => 346667]], $churn['mrr_lost']);
    }

    public function test_each_report_downloads_as_csv_and_is_audited(): void
    {
        $this->activate();
        $sales = $this->staffBearer($this->staff(PlatformUser::ROLE_SALES));

        $csv = $this->get('/api/v1/platform/reports/revenue.csv?from=2026-11-01&to=2026-11-30', $sales)->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $lines = array_values(array_filter(explode("\n", $csv->streamedContent())));
        $this->assertSame('section,key,currency,amount', $lines[0]);
        $this->assertContains('currency,all,KES,3466.67', $lines);

        foreach (['mrr', 'trials', 'churn', 'usage'] as $report) {
            $this->get("/api/v1/platform/reports/{$report}.csv", $sales)->assertOk();
        }

        $this->get('/api/v1/platform/reports/secrets.csv', $sales)->assertNotFound();
        $this->assertSame(5, PlatformDatabase::connection()->table('platform_audit_logs')->where('action', 'platform.report.export')->count());
    }

    public function test_only_staff_with_reports_see_them(): void
    {
        foreach ([PlatformUser::ROLE_SUPPORT, PlatformUser::ROLE_ENGINEERING] as $role) {
            $this->getJson('/api/v1/platform/reports/summary', $this->staffBearer($this->staff($role)))->assertForbidden();
            $this->get('/api/v1/platform/reports/mrr.csv', $this->staffBearer($this->staff($role)))->assertForbidden();
        }

        $this->getJson('/api/v1/platform/reports/summary', $this->headersFor())->assertUnauthorized();
        $this->getJson('/api/v1/platform/reports/summary?from=2026-11-30&to=2026-11-01', $this->staffBearer($this->staff()))->assertStatus(422);
    }

    /** Review L4: formulas are neutralised, plain numbers (negative ones too) stay numbers. */
    public function test_csv_cells_neutralise_formulas_but_keep_numbers(): void
    {
        $this->assertSame('-1200.00', PlatformReportsController::csvCell('-1200.00'));
        $this->assertSame("'=SUM(A1)", PlatformReportsController::csvCell('=SUM(A1)'));
        $this->assertSame("'-cmd", PlatformReportsController::csvCell('-cmd'));
        $this->assertSame(42, PlatformReportsController::csvCell(42));
    }

    /** Review L5: MRR by module adds up to MRR (annual discount shared out, overage left out). */
    public function test_mrr_by_module_adds_up_to_mrr(): void
    {
        $this->assertSame(['pos' => 300, 'payroll' => 701], PlatformReports::shareOut(1001, [['pos', 3000, 12], ['payroll', 7000, 12]], 'custom'));
        $this->assertSame(['custom' => 500], PlatformReports::shareOut(500, [], 'custom'));

        $annual = [...self::STARTER, 'cycle' => 'annual', 'modules' => ['inventory', 'loyalty', 'payroll', 'pos'], 'quantities' => ['outlet' => 1, 'employee' => 20]];
        $this->postJson('/api/v1/subscription/change', [...$annual, 'expected_charge' => 0], $this->headersFor())->assertOk();
        $this->activate();
        $mrr = $this->getJson('/api/v1/platform/reports/summary', $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE)))->assertOk()->json('data.mrr');

        $this->assertSame(array_sum(array_column($mrr['by_currency'], 'amount_minor')), array_sum(array_column($mrr['by_module'], 'amount_minor')));
        $this->assertCount(2, $mrr['by_module']);
    }

    /** ADM-01, ADM-06: the console shows each account's MRR net of tax, as the MRR report counts it. */
    public function test_the_tenants_console_shows_the_same_net_mrr_as_the_report(): void
    {
        $this->activate();
        $trial = $this->otherTenant();
        $this->asTenant($trial['user']->tenant_id, fn () => app(Subscriptions::class)->startTrial('KE', self::STARTER));
        $support = $this->staffBearer($this->staff(PlatformUser::ROLE_SUPPORT));

        // KES 3,900.00 less 433.33 at the pinned 12.5%; a trial pays nothing.
        $list = collect($this->getJson('/api/v1/platform/tenants', $support)->assertOk()->json('data'))->keyBy('tenant_id');
        $this->assertSame(['amount_minor' => 346667, 'currency' => 'KES'], $list[$this->owner->tenant_id]['mrr']);
        $this->assertSame(0, $list[$trial['user']->tenant_id]['mrr']['amount_minor']);
        $this->getJson("/api/v1/platform/tenants/{$this->owner->tenant_id}", $support)->assertOk()
            ->assertJsonPath('data.account.mrr', ['amount_minor' => 346667, 'currency' => 'KES']);

        $report = $this->getJson('/api/v1/platform/reports/summary', $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE)))->assertOk()->json('data.mrr.by_currency');
        $this->assertSame(346667, $report[0]['amount_minor']);
    }

    /**
     * ADM-06, Phase 8 walkthrough: an account synced before operator_accounts.bundle existed
     * has no bundle, and MRR by plan put its whole MRR under "custom" while revenue by plan
     * (from the invoice's bundle line) said Starter. MRR by plan falls back to the period
     * invoice's bundle line, as MRR by module already reads its lines.
     */
    public function test_mrr_by_plan_agrees_with_revenue_by_plan_when_the_account_has_no_bundle(): void
    {
        $this->activate();
        $this->inOperator(fn () => OperatorAccount::query()->where('customer_tenant_id', $this->owner->tenant_id)->update(['bundle' => null]));

        $data = $this->getJson('/api/v1/platform/reports/summary?from=2026-11-01&to=2026-11-30', $this->staffBearer($this->staff(PlatformUser::ROLE_FINANCE)))->assertOk()->json('data');

        $this->assertSame(['starter'], array_column($data['mrr']['by_plan'], 'key'));
        $this->assertSame(['starter'], array_column($data['revenue']['by_plan'], 'key'));
        $this->assertSame(346667, $data['mrr']['by_plan'][0]['amount_minor']);
    }

    /** CP-02: with no rate on the invoice (none confirmed), MRR is left as billed, never guessed. */
    public function test_mrr_without_a_tax_rate_is_left_as_billed(): void
    {
        $this->assertSame(390000, PlatformReports::netOfTax(390000, null));
        $this->assertSame(390000, PlatformReports::netOfTax(390000, new OperatorInvoice(['tax_rate' => null])));
        $this->assertSame(336207, PlatformReports::netOfTax(390000, new OperatorInvoice(['tax_rate' => '16.0000'])));
    }
}
