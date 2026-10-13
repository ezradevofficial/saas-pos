<?php

namespace Modules\Accounting\Tests;

use Illuminate\Http\UploadedFile;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-12 to ACC-14: statement import (CSV, M-Pesa), matching, rules and auto-match. */
class BankReconciliationTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    private function file(string $content, string $name = 'statement.csv'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'acc-statement-');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function import(string $bankId, UploadedFile $file, string $format, array $mapping = []): array
    {
        return $this->post("/api/v1/accounting/bank-accounts/{$bankId}/statements", ['file' => $file, 'format' => $format, 'mapping' => $mapping], [...$this->headersFor(), 'Accept' => 'application/json'])
            ->assertCreated()->json('data');
    }

    public function test_a_csv_statement_imports_with_its_mapping_and_skips_lines_already_imported(): void
    {
        $csv = "Date,Narrative,Ref,Money in,Money out\n12/10/2026,Deposit Baraka,DEP1,\"1,500.00\",\n13/10/2026,Bank charges,,,(35.00)\n";
        $mapping = ['date' => 'Date', 'description' => 'Narrative', 'reference' => 'Ref', 'money_in' => 'Money in', 'money_out' => 'Money out', 'date_format' => 'd/m/Y'];

        $first = $this->import($this->bank->id, $this->file($csv), 'csv', $mapping);
        $again = $this->import($this->bank->id, $this->file($csv), 'csv', $mapping);

        $this->assertSame([2, 0, '2026-10-12', '2026-10-13'], [$first['lines'], $first['skipped'], $first['starts_on'], $first['ends_on']]);
        $this->assertSame([0, 2], [$again['lines'], $again['skipped']]);
        $this->inTenant(fn () => $this->assertSame([150000, -3500], BankStatementLine::query()->orderBy('date')->pluck('amount_minor')->all()));
    }

    public function test_an_mpesa_statement_keeps_completed_transactions_only(): void
    {
        $csv = "Organisation Name,Amani Retail\nStatement Period,01-10-2026 - 15-10-2026\n\n"
            ."Receipt No.,Completion Time,Initiation Time,Details,Transaction Status,Paid In,Withdrawn,Balance\n"
            ."QJK1AB2CD3,2026-10-12 09:15:23,2026-10-12 09:15:20,Customer Payment from 2547XXXX,Completed,1125.00,,5125.00\n"
            ."QJK1AB2CD4,2026-10-12 09:16:00,2026-10-12 09:16:00,Pay Bill Charge,Completed,,-15.00,5110.00\n"
            ."QJK1AB2CD5,2026-10-12 10:00:00,2026-10-12 10:00:00,Customer Payment,Failed,500.00,,5110.00\n";

        $result = $this->import($this->mpesa->id, $this->file($csv, 'mpesa.csv'), 'mpesa');

        $this->assertSame(2, $result['lines']);
        $this->inTenant(fn () => $this->assertSame(['QJK1AB2CD3', 'QJK1AB2CD4'], BankStatementLine::query()->orderBy('reference')->pluck('reference')->all()));
        $this->post("/api/v1/accounting/bank-accounts/{$this->mpesa->id}/statements", ['file' => $this->file("a,b\n1,2\n"), 'format' => 'mpesa'], [...$this->headersFor(), 'Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('code', 'statement_not_mpesa');
    }

    public function test_matching_suggestions_rules_and_unmatch(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '1125.00']]);
        $this->api('POST', $this->companyUri('payments'), ['kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $this->mpesa->id,
            'date' => '2026-10-12', 'amount' => '1125.00', 'reference' => 'QJK1AB2CD3', 'allocations' => [['document_id' => $invoice['id'], 'amount' => '1125.00']]])->assertCreated();
        $csv = "Receipt No.,Completion Time,Details,Transaction Status,Paid In,Withdrawn\nQJK1AB2CD3,2026-10-12 09:15:23,Customer Payment,Completed,1125.00,\nQJK1AB2CD4,2026-10-12 09:16:00,Pay Bill Charge,Completed,,-15.00\n";
        $this->import($this->mpesa->id, $this->file($csv), 'mpesa');

        $view = $this->api('GET', "accounting/bank-accounts/{$this->mpesa->id}/reconciliation")->assertOk()->json('data');
        $this->assertCount(2, $view['statement_lines']);
        $this->assertCount(1, $view['ledger_lines']);
        $receiptLine = collect($view['statement_lines'])->firstWhere('reference', 'QJK1AB2CD3');
        $this->assertSame([$view['ledger_lines'][0]['id']], $view['suggestions'][$receiptLine['id']]);

        // A wrong total is refused; the right line matches; unmatching puts it back.
        $fee = collect($view['statement_lines'])->firstWhere('reference', 'QJK1AB2CD4');
        $this->api('POST', "accounting/bank-statement-lines/{$fee['id']}/match", ['journal_line_ids' => [$view['ledger_lines'][0]['id']]])->assertUnprocessable()->assertJsonPath('code', 'match_amount');
        $this->api('POST', "accounting/bank-statement-lines/{$receiptLine['id']}/match", ['journal_line_ids' => [$view['ledger_lines'][0]['id']]])->assertOk()->assertJsonPath('data.status', 'matched');
        $this->api('POST', "accounting/bank-statement-lines/{$receiptLine['id']}/unmatch")->assertOk()->assertJsonPath('data.status', 'unmatched');

        // Auto-match: the receipt by amount, the fee by a rule (posted to bank charges).
        $this->api('POST', $this->companyUri('bank-rules'), ['name' => 'M-Pesa charges', 'contains' => 'charge', 'direction' => 'out', 'account_id' => $this->account('6400')->id])->assertCreated();
        $this->api('POST', "accounting/bank-accounts/{$this->mpesa->id}/auto-match")->assertOk()->assertJsonPath('data', ['matched' => 1, 'ruled' => 1]);
        $summary = $this->api('GET', "accounting/bank-accounts/{$this->mpesa->id}/reconciliation")->json('data.summary');
        $this->assertSame(['statement_balance' => '111000', 'ledger_balance' => '111000', 'unmatched_statement' => '0', 'unmatched_ledger' => '0'], array_map(fn ($m) => $m['amount_minor'], $summary));
    }

    public function test_a_bank_account_needs_a_postable_asset_account_and_a_foreign_one_fixes_its_currency(): void
    {
        $this->api('POST', $this->companyUri('bank-accounts'), ['name' => 'Wrong', 'kind' => 'bank', 'account_id' => $this->account('4100')->id, 'currency' => 'KES'])
            ->assertUnprocessable()->assertJsonPath('code', 'bank_gl_account');
        $this->api('POST', $this->companyUri('bank-accounts'), ['name' => 'USD', 'kind' => 'bank', 'account_id' => $this->account('1110')->id, 'currency' => 'USD'])->assertCreated();
        $this->api('GET', 'accounting/accounts/'.$this->account('1110')->id)->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.revalue', true);
    }
}
