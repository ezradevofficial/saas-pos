<?php

namespace Modules\Accounting\Tests;

use App\Core\MasterData\Taxes\TaxCode;
use App\Core\MasterData\Taxes\TaxRate;
use App\Core\Rbac\Scope;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-09 to ACC-11, ACC-19: invoices, credit notes, receipts and payments, realised FX, ageing and statements. */
class DocumentsTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    /** @return array<string, array{debit: int, credit: int}> base amounts by account code */
    private function journalLines(string $journalId): array
    {
        return $this->inTenant(fn () => JournalLine::query()->where('journal_id', $journalId)->with('account')->get()
            ->groupBy(fn ($line) => $line->account->code)
            ->map(fn ($lines) => ['debit' => $lines->sum('base_debit_minor'), 'credit' => $lines->sum('base_credit_minor')])->all());
    }

    public function test_a_customer_invoice_posts_receivable_revenue_and_tax_and_is_numbered(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'qty' => '2', 'price' => '500.00', 'tax' => $this->vat->id]]);

        $this->assertSame('posted', $invoice['status']);
        $this->assertStringStartsWith('INV-2026-', $invoice['number']);
        $this->assertSame(['100000', '12500', '112500', '112500'], [$invoice['subtotal']['amount_minor'], $invoice['tax']['amount_minor'], $invoice['total']['amount_minor'], $invoice['open']['amount_minor']]);
        $this->assertSame('2026-11-09', $invoice['due_date']);
        $this->assertSame([
            '1200' => ['debit' => 112500, 'credit' => 0],
            '2200' => ['debit' => 0, 'credit' => 12500],
            '4100' => ['debit' => 0, 'credit' => 100000],
        ], collect($this->journalLines($invoice['journal_id']))->sortKeys()->all());

        // A posted document never changes.
        $this->api('PATCH', "accounting/documents/{$invoice['id']}", ['memo' => 'x'])->assertUnprocessable()->assertJsonPath('code', 'document_not_draft');
    }

    public function test_a_code_whose_rate_is_needed_saves_a_draft_but_refuses_posting(): void
    {
        $needed = $this->inTenant(function () {
            $code = TaxCode::create(['company_id' => $this->acme->id, 'code' => 'VAT_X', 'name' => 'VAT unconfirmed', 'kind' => 'vat']);
            TaxRate::create(['tax_code_id' => $code->id, 'rate' => null, 'needs_confirmation' => true, 'effective_from' => '2026-01-01']);

            return $code;
        });
        $draft = $this->api('POST', $this->companyUri('documents'), [
            'kind' => 'customer_invoice', 'party_id' => $this->customer->id, 'issue_date' => '2026-10-10',
            'lines' => [['description' => 'Goods', 'account_id' => $this->account('4100')->id, 'qty' => '1', 'unit_price' => '100.00', 'tax_code_id' => $needed->id]],
        ])->assertCreated();
        $draft->assertJsonPath('data.rate_needed', true)->assertJsonPath('data.lines.0.rate_needed', true);

        $this->api('POST', 'accounting/documents/'.$draft->json('data.id').'/post')->assertUnprocessable()->assertJsonPath('code', 'tax_rate_missing');
    }

    public function test_a_credit_note_settles_its_invoice_and_never_credits_more_than_it(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '1000.00']]);
        $credit = $this->postedDocument('customer_credit', [['account' => '4300', 'price' => '400.00']], ['credits_document_id' => $invoice['id']]);

        $this->assertStringStartsWith('CN-2026-', $credit['number']);
        $this->assertSame('0', $credit['open']['amount_minor']);
        $this->api('GET', "accounting/documents/{$invoice['id']}")->assertJsonPath('data.open.amount_minor', '60000');

        $draft = $this->api('POST', $this->companyUri('documents'), [
            'kind' => 'customer_credit', 'party_id' => $this->customer->id, 'issue_date' => '2026-10-11', 'credits_document_id' => $invoice['id'],
            'lines' => [['description' => 'Too much', 'account_id' => $this->account('4300')->id, 'qty' => '1', 'unit_price' => '700.00']],
        ])->assertCreated()->json('data.id');
        $this->api('POST', "accounting/documents/{$draft}/post")->assertUnprocessable()->assertJsonPath('code', 'credit_exceeds_invoice');

        // A customer credit note names its invoice (fiscal credit notes need the original).
        $this->api('POST', $this->companyUri('documents'), [
            'kind' => 'customer_credit', 'party_id' => $this->customer->id, 'issue_date' => '2026-10-11',
            'lines' => [['description' => 'x', 'account_id' => $this->account('4300')->id, 'qty' => '1', 'unit_price' => '1.00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('credits_document_id');
    }

    public function test_a_receipt_posts_to_the_bank_and_settles_invoices_leaving_the_rest_on_account(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '1000.00']]);
        $receipt = $this->api('POST', $this->companyUri('payments'), [
            'kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $this->mpesa->id, 'date' => '2026-10-12', 'amount' => '1500.00', 'reference' => 'QK7H2',
            'allocations' => [['document_id' => $invoice['id'], 'amount' => '1000.00']],
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('RCT-2026-', $receipt['number']);
        $this->assertSame('50000', $receipt['unallocated']['amount_minor']);
        $this->api('GET', "accounting/documents/{$invoice['id']}")->assertJsonPath('data.open.amount_minor', '0');
        $this->assertSame(['1130' => ['debit' => 150000, 'credit' => 0], '1200' => ['debit' => 0, 'credit' => 150000]], collect($this->journalLines($receipt['journal_id']))->sortKeys()->all());

        // More than is open is refused.
        $second = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $this->api('POST', "accounting/payments/{$receipt['id']}/allocate", ['allocations' => [['document_id' => $second['id'], 'amount' => '600.00']]])
            ->assertUnprocessable()->assertJsonPath('code', 'allocation_too_much');
        $this->api('POST', "accounting/payments/{$receipt['id']}/allocate", ['allocations' => [['document_id' => $second['id'], 'amount' => '100.00']]])
            ->assertOk()->assertJsonPath('data.unallocated.amount_minor', '40000');
    }

    public function test_a_usd_invoice_paid_at_a_higher_rate_posts_the_realised_gain(): void
    {
        $this->rate('USD', 'KES', '129.5', at: '2026-10-01T00:00:00Z');
        $usdBank = $this->api('POST', $this->companyUri('bank-accounts'), [
            'name' => 'USD account', 'kind' => 'bank', 'account_id' => $this->api('POST', $this->companyUri('accounts'), [
                'code' => '1125', 'name' => 'Bank USD', 'type' => 'asset', 'subtype' => 'bank', 'parent_id' => $this->account('1100')->id, 'cash_flow' => 'cash',
            ])->assertCreated()->json('data.id'), 'currency' => 'USD',
        ])->assertCreated()->json('data');

        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']], ['currency' => 'USD']);
        $this->assertSame('1295000', $invoice['base_total']['amount_minor']);

        $this->rate('USD', 'KES', '140', at: '2026-10-11T00:00:00Z');
        $receipt = $this->api('POST', $this->companyUri('payments'), [
            'kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $usdBank['id'], 'date' => '2026-10-12', 'amount' => '100.00',
            'allocations' => [['document_id' => $invoice['id'], 'amount' => '100.00']],
        ])->assertCreated()->json('data');

        $this->assertSame('1400000', $receipt['base_amount']['amount_minor']);
        $this->assertSame('105000', $receipt['allocations'][0]['fx_difference']['amount_minor']);
        $fx = $this->journalLines($receipt['allocations'][0]['journal_id']);
        $this->assertSame(['debit' => 105000, 'credit' => 0], $fx['1200']);
        $this->assertSame(['debit' => 0, 'credit' => 105000], $fx['7100']);
        // The receivable is back to zero in base currency.
        $tb = collect($this->api('GET', $this->companyUri('reports/trial-balance').'?from=2026-01-01&to=2026-12-31')->assertOk()->json('data.rows'))->keyBy('account.code');
        $this->assertSame(0, $tb['1200']['closing']);
    }

    public function test_ageing_and_the_party_statement(): void
    {
        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '300.00']], ['due_date' => '2026-08-01', 'issue_date' => '2026-07-15']);
        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '200.00']]);
        $this->api('POST', $this->companyUri('payments'), ['kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => '2026-10-13', 'amount' => '50.00'])->assertCreated();

        $ageing = $this->api('GET', $this->companyUri('reports/ageing').'?kind=customer&to=2026-10-15')->assertOk()->json('data');
        $row = $ageing['rows'][0];
        $this->assertSame('Baraka Traders', $row['party']['name']);
        $this->assertSame(30000, $row['buckets']['61_90']);
        $this->assertSame(20000 - 5000, $row['buckets']['current']);
        $this->assertSame(45000, $row['total']);

        $statement = $this->api('GET', $this->companyUri('reports/party-statement')."?party={$this->customer->id}&kind=customer&from=2026-08-01&to=2026-10-31")->assertOk()->json('data');
        $this->assertSame(30000, $statement['opening']);
        $this->assertSame(45000, $statement['closing']);
        $this->assertSame(['customer_invoice', 'receipt'], array_column($statement['entries'], 'type'));
    }

    public function test_documents_need_their_permissions_and_the_party_role(): void
    {
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $body = ['kind' => 'customer_invoice', 'party_id' => $this->supplier->id, 'issue_date' => '2026-10-10',
            'lines' => [['description' => 'x', 'account_id' => $this->account('4100')->id, 'qty' => '1', 'unit_price' => '1.00']]];

        $this->api('POST', $this->companyUri('documents'), $body)->assertUnprocessable()->assertJsonPath('code', 'party_not_customer');
        $this->api('POST', $this->companyUri('documents'), [...$body, 'party_id' => $this->customer->id], $auditor)->assertForbidden();
        $this->api('GET', $this->companyUri('documents'), [], $auditor)->assertOk();
        $this->api('POST', $this->companyUri('documents'), [...$body, 'party_id' => $this->customer->id, 'lines' => [[...$body['lines'][0], 'account_id' => $this->account('1200')->id]]])
            ->assertUnprocessable()->assertJsonPath('code', 'document_account');
    }

    public function test_a_posted_invoice_prints_from_its_template_and_lists_export(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '250.00']]);
        $pdf = $this->api('GET', "accounting/documents/{$invoice['id']}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());

        $csv = $this->get('/api/v1/'.$this->companyUri('documents').'?format=csv&status=all', $this->headersFor())->assertOk()->streamedContent();
        $this->assertStringContainsString($invoice['number'], $csv);
        $this->assertStringContainsString('KES 250.00', $csv);
    }
}
