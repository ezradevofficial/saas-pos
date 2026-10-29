<?php

namespace Modules\Accounting\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Currency\Money;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-36: the tax return of a period: per tax code and rate, from posted transactions only, reconciled to the ledger. */
class TaxReturnTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    private function returnUri(string $tail = ''): string
    {
        return $this->companyUri('reports/tax-return'.$tail).'?from=2026-10-01&to=2026-10-31';
    }

    /** A module-style journal (what POS posts): tender, revenue and tax per code, through the posting rules. */
    private function moduleSale(string $tax, string $date = '2026-10-12'): void
    {
        $this->inTenant(function () use ($tax, $date) {
            PostingRule::query()->firstOrCreate(['company_id' => $this->acme->id, 'event_type' => 'test.sale'], ['lines' => [
                ['field' => 'tender', 'side' => 'debit', 'account_id' => $this->account('1120')->id],
                ['field' => 'revenue', 'side' => 'credit', 'account_id' => $this->account('4100')->id],
                ['field' => 'tax.*', 'side' => 'credit', 'account_id' => $this->account('2200')->id],
            ]]);
            app(PostingService::class)->post(new SourceDocument($this->acme->id, 'test.sale', (string) Str::uuid7(), CarbonImmutable::parse($date), 'KES',
                ['tender' => (string) (10000 + (int) $tax), 'revenue' => '10000', 'tax.'.$this->vat->id => $tax], reference: 'SALE-1'));
        });
    }

    public function test_the_return_totals_per_code_and_rate_and_reconcile_with_the_posted_tax_lines(): void
    {
        // Output: an invoice (tax 125.00 on 1,000.00), a credit note against it (tax 50.00 on 400.00), a module sale (tax 12.50).
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'qty' => '2', 'price' => '500.00', 'tax' => $this->vat->id]]);
        $credit = $this->postedDocument('customer_credit', [['account' => '4300', 'price' => '400.00', 'tax' => $this->vat->id]], ['credits_document_id' => $invoice['id']]);
        $this->moduleSale('1250');
        // Input: a bill (tax 25.00 on 200.00).
        $bill = $this->postedDocument('supplier_invoice', [['account' => '5200', 'price' => '200.00', 'tax' => $this->vat->id]]);
        // A manual journal on the sales-tax account has no tax code and is shown as such.
        $this->inTenant(fn () => app(JournalPoster::class)->record($this->acme, ['date' => CarbonImmutable::parse('2026-10-14'), 'kind' => 'system', 'source_type' => 'test.manual', 'source_id' => (string) Str::uuid7()], [
            new LineInput($this->account('1110')->id, LineInput::DEBIT, Money::ofMinor(1000, 'KES')),
            new LineInput($this->account('2200')->id, LineInput::CREDIT, Money::ofMinor(1000, 'KES')),
        ], $this->owner));
        // Outside the period and not posted: neither counts.
        $this->moduleSale('9900', '2026-09-30');
        $this->api('POST', $this->companyUri('documents'), [
            'kind' => 'customer_invoice', 'party_id' => $this->customer->id, 'issue_date' => '2026-10-10',
            'lines' => [['description' => 'Draft', 'account_id' => $this->account('4100')->id, 'qty' => '1', 'unit_price' => '999.00', 'tax_code_id' => $this->vat->id]],
        ])->assertCreated();

        $data = $this->api('GET', $this->returnUri(), [], $this->accountant)->assertOk()->json('data');

        $this->assertSame(['vat_return', 'KES', '2026-10-01', '2026-10-31'], [$data['form'], $data['currency'], $data['from'], $data['to']]);
        $rows = collect($data['rows'])->keyBy(fn ($row) => $row['direction'].'|'.($row['code'] ?? 'none'));
        // Output with the code at 12.5 %: invoice 12,500 + credit note -5,000 + module sale 1,250; the taxable value is complete only for documents.
        $this->assertSame(['12.5', 8750, false, 3], [$rows['output|VAT_T']['rate'], $rows['output|VAT_T']['tax_minor'], $rows['output|VAT_T']['taxable_complete'], $rows['output|VAT_T']['entries']]);
        $this->assertSame(60000, $rows['output|VAT_T']['taxable_minor']);
        $this->assertSame(1000, $rows['output|none']['tax_minor']);
        $this->assertNull($rows['output|none']['rate']);
        $this->assertSame([2500, 20000], [$rows['input|VAT_T']['tax_minor'], $rows['input|VAT_T']['taxable_minor']]);
        $this->assertSame(['output_minor' => 9750, 'input_minor' => 2500, 'net_payable_minor' => 7250], $data['totals']);
        // The same figures as the tax accounts of the ledger (2200 credits less debits, 1400 debits less credits).
        $this->assertSame(['output_minor' => 9750, 'input_minor' => 2500], $data['ledger']);
        $this->assertTrue($data['reconciled']);
        $this->assertEqualsCanonicalizing(['statutory_boxes_unmapped', 'taxable_value_partial', 'no_tax_code'], $data['notes']);
        $this->assertSame(['documents' => 2, 'accepted' => 0, 'pending' => 2], $data['fiscal']);

        // The module's tax line kept its code; the documents' single tax line does not need one.
        // Two module sales posted (one before the period): two coded lines, and no other line has a code.
        $this->assertSame(2, $this->inTenant(fn () => JournalLine::query()->where('tax_code_id', $this->vat->id)->count()));
        $this->assertSame(2, $this->inTenant(fn () => JournalLine::query()->whereNotNull('tax_code_id')->count()));

        // The documents behind the output row: the invoice, the credit note and the module sale, with the fiscal status of the documents.
        $entries = $this->api('GET', $this->returnUri('/entries').'&direction=output&tax_code='.$this->vat->id.'&rate=12.5', [], $this->accountant)->assertOk();
        $entries->assertJsonPath('meta.total', 3)->assertJsonPath('meta.currency', 'KES');
        $by = collect($entries->json('data'))->keyBy(fn ($e) => $e['document_number'] ?? $e['journal_number']);
        $this->assertSame([12500, 100000, $invoice['id']], [$by[$invoice['number']]['tax_minor'], $by[$invoice['number']]['taxable_minor'], $by[$invoice['number']]['document_id']]);
        $this->assertSame([-5000, -40000], [$by[$credit['number']]['tax_minor'], $by[$credit['number']]['taxable_minor']]);
        $this->assertNull($by[$invoice['number']]['fiscal']);
        $this->assertSame(['test.sale', null], [collect($entries->json('data'))->firstWhere('document_id', null)['source_type'], collect($entries->json('data'))->firstWhere('document_id', null)['taxable_minor']]);
        $this->api('GET', $this->returnUri('/entries').'&direction=input', [], $this->accountant)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.document_number', $bill['number']);
        $this->api('GET', $this->returnUri('/entries').'&tax_code=none', [], $this->accountant)->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tax_minor', 1000);
    }

    public function test_the_csv_export_lists_every_line_and_is_audited(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'qty' => '2', 'price' => '500.00', 'tax' => $this->vat->id]]);
        $this->moduleSale('1250');

        $response = $this->api('GET', $this->returnUri('/export'), [], $this->accountant)->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Section,"Tax code"', $csv);
        $this->assertStringContainsString('"Output tax",VAT_T,"VAT test",VAT,12.5,Invoice,'.$invoice['number'].',2026-10-10,', $csv);
        $this->assertStringContainsString(',1000.00,125.00,KES', $csv);
        $this->assertStringContainsString('"Output tax",VAT_T,"VAT test",VAT,12.5,Journal', $csv);
        $this->assertStringContainsString(',test.sale,,12.50,KES', $csv);
        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'accounting.tax_return.export')->sole());
        $this->assertSame(['csv', 2, '2026-10-01', '2026-10-31'], [$audit->after['format'], $audit->after['rows'], $audit->after['from'], $audit->after['to']]);
        $this->assertNotNull($audit->actor_id ?? $audit->user_id ?? true);
    }

    public function test_a_period_with_no_data_returns_zero_totals_and_reconciles(): void
    {
        $data = $this->api('GET', $this->companyUri('reports/tax-return').'?from=2026-01-01&to=2026-01-31', [], $this->accountant)->assertOk()->json('data');

        $this->assertSame([], $data['rows']);
        $this->assertSame(['output_minor' => 0, 'input_minor' => 0, 'net_payable_minor' => 0], $data['totals']);
        $this->assertTrue($data['reconciled']);
        $this->assertSame(['statutory_boxes_unmapped'], $data['notes']);
        $this->api('GET', $this->companyUri('reports/tax-return/entries').'?from=2026-01-01&to=2026-01-31', [], $this->accountant)->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('data', []);
        $this->api('GET', $this->companyUri('reports/tax-return/export').'?from=2026-01-01&to=2026-01-31', [], $this->accountant)->assertOk();
    }

    public function test_the_period_is_validated(): void
    {
        $this->api('GET', $this->companyUri('reports/tax-return'), [], $this->accountant)->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->api('GET', $this->companyUri('reports/tax-return').'?from=2026-10-31&to=2026-10-01', [], $this->accountant)->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->api('GET', $this->returnUri('/entries').'&tax_code='.Str::uuid(), [], $this->accountant)->assertUnprocessable()->assertJsonValidationErrors(['tax_code']);
    }

    public function test_it_needs_the_report_permission_at_the_company_and_another_tenant_sees_nothing(): void
    {
        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00', 'tax' => $this->vat->id]]);
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        // Reads journals (to approve them) but has no report permission.
        $approver = $this->userWith('approver', Scope::company($this->acme->id));
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));

        foreach (['', '/entries', '/export'] as $tail) {
            $this->api('GET', $this->returnUri($tail), [], $cashier)->assertNotFound();
            $this->api('GET', $this->returnUri($tail), [], $approver)->assertForbidden();
        }

        $this->api('GET', $this->returnUri(), [], $auditor)->assertOk();

        // Another tenant: it cannot read this company's return, and its own company's return holds none of these figures.
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('accounting'));
        $this->getJson('/api/v1/'.$this->returnUri(), $this->headersFor($other['user']))->assertNotFound();
        $this->getJson('/api/v1/'.$this->returnUri('/export'), $this->headersFor($other['user']))->assertNotFound();
        $mine = $this->getJson("/api/v1/companies/{$other['company']->id}/accounting/reports/tax-return?from=2026-10-01&to=2026-10-31", $this->headersFor($other['user']))->assertOk();
        $mine->assertJsonPath('data.rows', [])->assertJsonPath('data.totals.output_minor', 0);
    }
}
