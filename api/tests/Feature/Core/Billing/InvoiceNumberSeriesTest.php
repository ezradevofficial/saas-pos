<?php

namespace Tests\Feature\Core\Billing;

use App\Core\Billing\Invoicing\Invoicing;
use App\Core\Billing\Models\OperatorInvoice;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\NumberFormat;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsBilling;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Concerns\ResetsPlatformTables;
use Tests\TestCase;

/**
 * NUM-01, NUM-02, SUB-03: each operator selling company (KE, CD) numbers its
 * platform invoices in its own gapless yearly series, for its own tax filings.
 */
class InvoiceNumberSeriesTest extends TestCase
{
    use BuildsBilling, RefreshTenantDatabase, ResetsPlatformTables;

    private int $issued = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBilling();
    }

    private function issue(string $country): string
    {
        $this->inTenant(fn () => DB::connection(TenantContext::CONNECTION)->table('subscriptions')->update(['country' => $country]));
        $subscription = $this->subscription();
        // One open invoice per subscription and period: each call takes its own period.
        $now = CarbonImmutable::now();
        $start = $now->addDays($this->issued++);

        $invoice = $this->inTenant(fn () => app(Invoicing::class)->issue(
            $subscription, 'renewal', app(Invoicing::class)->prorationLines(10000, 10, 30), $start, $start->addMonth(), $now->addDays(7),
        ));

        return $invoice->number;
    }

    public function test_ke_and_cd_invoices_issued_alternately_have_independent_gapless_series(): void
    {
        $numbers = [];

        foreach (['KE', 'CD', 'KE', 'CD', 'CD', 'KE'] as $country) {
            $numbers[$country][] = $this->issue($country);
        }

        $this->assertSame(['KE-INV-2026-000001', 'KE-INV-2026-000002', 'KE-INV-2026-000003'], $numbers['KE']);
        $this->assertSame(['CD-INV-2026-000001', 'CD-INV-2026-000002', 'CD-INV-2026-000003'], $numbers['CD']);

        // The operator's own copies carry the same numbers, none twice.
        $all = $this->inOperator(fn () => OperatorInvoice::query()->pluck('number')->all());
        $this->assertCount(6, array_unique($all));
    }

    public function test_the_next_year_restarts_the_series_and_a_company_series_is_created_once(): void
    {
        $this->issue('KE');
        $this->travelToDay('2027-01-05 08:00:00');
        $this->assertSame('KE-INV-2027-000001', $this->issue('KE'));

        $formats = $this->inOperator(fn () => NumberFormat::query()->where('document_type', Invoicing::NUMBER_TYPE)->whereNotNull('company_id')->get());
        $this->assertCount(1, $formats);
        $this->assertTrue($formats[0]->gapless);
    }

    public function test_numbers_issued_before_the_per_company_series_keep_their_number(): void
    {
        // An invoice from the old shared series, as already issued.
        $first = $this->issue('KE');
        $this->inOperator(fn () => DB::connection(TenantContext::CONNECTION)->table('operator_invoices')->where('number', $first)->update(['number' => 'INV-2026-000004']));
        $this->inTenant(fn () => DB::connection(TenantContext::CONNECTION)->table('billing_invoices')->where('number', $first)->update(['number' => 'INV-2026-000004']));

        $this->assertSame('KE-INV-2026-000002', $this->issue('KE'));
        $this->assertSame('CD-INV-2026-000001', $this->issue('CD'));

        $this->assertSame(1, $this->inOperator(fn () => OperatorInvoice::query()->where('number', 'INV-2026-000004')->count()));
    }

    public function test_ensuring_a_company_series_twice_keeps_the_first_format_and_one_row(): void
    {
        $this->inOperator(function () {
            $company = Company::query()->where('country', 'KE')->firstOrFail();
            $numbering = app(Numbering::class);

            $a = $numbering->ensureCompanyFormat(Invoicing::NUMBER_TYPE, $company->id, 'KE-INV-{YYYY}-{000001}', NumberFormat::RESET_YEARLY, true);
            $b = $numbering->ensureCompanyFormat(Invoicing::NUMBER_TYPE, $company->id, 'OTHER-{000001}', NumberFormat::RESET_NEVER, false);

            $this->assertSame($a->id, $b->id);
            $this->assertSame('KE-INV-{YYYY}-{000001}', $b->pattern);

            $number = DB::connection(TenantContext::CONNECTION)->transaction(
                fn () => $numbering->next(Invoicing::NUMBER_TYPE, new NumberContext($company, at: now()))->number,
            );
            $this->assertSame('KE-INV-2026-000001', $number);
        });
    }
}
