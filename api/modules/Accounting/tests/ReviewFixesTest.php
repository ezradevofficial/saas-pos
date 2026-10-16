<?php

namespace Modules\Accounting\Tests;

use App\Core\Currency\Money;
use App\Core\Currency\TenantCurrencies;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\BankStatementLine;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\BuildsImports;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** Review findings on feat/accounting, one test (or more) per finding. */
class ReviewFixesTest extends TestCase
{
    use BuildsAccounting, BuildsImports, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    private function closeJanuary(): Period
    {
        $this->api('GET', $this->companyUri('periods').'?year=2026')->assertOk();
        $january = $this->inTenant(fn () => Period::query()->where('company_id', $this->acme->id)->where('starts_on', '2026-01-01')->sole());
        $this->api('POST', "accounting/periods/{$january->id}/close")->assertOk();

        return $january;
    }

    private function money(string $minor): Money
    {
        return Money::ofMinor($minor, 'KES');
    }

    // 1. Back-dated posting before a closed period.
    public function test_nothing_is_dated_before_the_latest_closed_period_and_no_open_year_is_created_there(): void
    {
        $this->closeJanuary();

        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2025-12-15', [['6200', '10.00', null], ['1120', null, '10.00']]))
            ->assertUnprocessable()->assertJsonPath('code', 'period_closed');
        $this->assertSame(['closed'], array_values(array_unique(array_column($this->api('GET', $this->companyUri('periods').'?year=2025')->assertOk()->json('data'), 'status'))));

        // The database refuses it too, even through an open period made by hand.
        $this->inTenant(function () {
            $open = Period::query()->create(['company_id' => $this->acme->id, 'fiscal_year' => 2024, 'number' => 12, 'starts_on' => '2024-12-01', 'ends_on' => '2024-12-31', 'status' => 'open']);

            try {
                DB::transaction(fn () => Journal::query()->create(['company_id' => $this->acme->id, 'date' => '2024-12-15', 'period_id' => $open->id, 'kind' => 'manual',
                    'status' => 'posted', 'posted_at' => now(), 'base_currency' => 'KES']));
                $this->fail('Posted before a closed period.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_period_closed', $e->getMessage());
            }
        });
    }

    // 2. Reversal only of manual, recurring, opening and other modules' journals.
    public function test_subledger_journals_cannot_be_reversed_by_hand_but_posting_rule_journals_can(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $this->api('POST', "accounting/journals/{$invoice['journal_id']}/reverse")->assertUnprocessable()->assertJsonPath('code', 'journal_not_reversible');

        $this->inTenant(fn () => PostingRule::create(['company_id' => $this->acme->id, 'event_type' => 'test.sale', 'lines' => [
            ['field' => 'total', 'side' => 'debit', 'account_id' => $this->account('1110')->id], ['field' => 'total', 'side' => 'credit', 'account_id' => $this->account('4100')->id],
        ]]));
        $journal = $this->inTenant(fn () => app(PostingService::class)->post(new SourceDocument($this->acme->id, 'test.sale', (string) Str::uuid7(), CarbonImmutable::parse('2026-10-12T08:00:00Z'), 'KES', ['total' => '1000']))->journal);
        $this->api('POST', "accounting/journals/{$journal->id}/reverse")->assertCreated();
    }

    // 3. Elimination sign when intercompany balances do not cancel.
    public function test_consolidation_balances_when_intercompany_balances_do_not_cancel(): void
    {
        $congo = $this->inTenant(function () {
            $congo = $this->company('Acme Congo');
            $congo->forceFill(['base_currency' => 'USD', 'country' => 'CD'])->save();
            app(TenantCurrencies::class)->provisionFor($congo);
            app(Charts::class)->fromTemplate($congo);

            return $congo;
        });
        $this->rate('USD', 'KES', '129.5', at: '2026-09-01T00:00:00Z', company: $congo);
        $dueFrom = $this->api('POST', $this->companyUri('accounts'), ['code' => '1250', 'name' => 'Due from Congo', 'type' => 'asset', 'subtype' => 'intercompany', 'counterparty_company_id' => $congo->id])->json('data.id');
        $dueTo = $this->api('POST', "companies/{$congo->id}/accounting/accounts", ['code' => '4621', 'name' => 'Due to Acme', 'type' => 'liability', 'subtype' => 'intercompany', 'counterparty_company_id' => $this->acme->id])->json('data.id');
        $this->inTenant(function () use ($congo, $dueFrom, $dueTo) {
            $poster = app(JournalPoster::class);
            $poster->record($this->acme, ['date' => CarbonImmutable::parse('2026-10-05'), 'kind' => 'system', 'source_type' => 'test.ic', 'source_id' => (string) Str::uuid7()], [
                new LineInput($dueFrom, LineInput::DEBIT, $this->money('1295000')), new LineInput($this->account('4200')->id, LineInput::CREDIT, $this->money('1295000')),
            ], $this->owner);
            $expense = Account::query()->where('company_id', $congo->id)->where('code', '622')->value('id');
            // Congo booked only USD 90 of the USD 100: KES 1,295.00 does not cancel.
            $poster->record($congo, ['date' => CarbonImmutable::parse('2026-10-05'), 'kind' => 'system', 'source_type' => 'test.ic', 'source_id' => (string) Str::uuid7()], [
                new LineInput($expense, LineInput::DEBIT, Money::ofMinor(9000, 'USD')), new LineInput($dueTo, LineInput::CREDIT, Money::ofMinor(9000, 'USD')),
            ], $this->owner);
        });

        $report = $this->api('GET', 'accounting/consolidation?currency=KES&from=2026-01-01&to=2026-10-31')->assertOk()->json('data');

        $this->assertSame(-129500, $report['eliminations']['difference']);
        $this->assertTrue($report['balanced']);
    }

    // 4. The invoice a credit note names is re-checked.
    public function test_a_credit_note_link_is_checked_on_update_and_again_at_posting(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $other = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Other customer', 'roles' => ['customer'], 'company_id' => $this->acme->id]));
        $draft = $this->api('POST', $this->companyUri('documents'), ['kind' => 'customer_credit', 'party_id' => $this->customer->id, 'issue_date' => '2026-10-11', 'credits_document_id' => $invoice['id'],
            'lines' => [['description' => 'Return', 'account_id' => $this->account('4300')->id, 'qty' => '1', 'unit_price' => '10.00']]])->assertCreated()->json('data.id');

        $this->api('PATCH', "accounting/documents/{$draft}", ['party_id' => $other->id])->assertUnprocessable()->assertJsonPath('code', 'credit_invoice_invalid');
        $this->api('PATCH', "accounting/documents/{$draft}", ['credits_document_id' => null])->assertUnprocessable()->assertJsonPath('code', 'credit_invoice_required');

        // Changed behind the API: posting checks again under its lock.
        $this->inTenant(fn () => Document::query()->whereKey($draft)->update(['party_id' => $other->id]));
        $this->api('POST', "accounting/documents/{$draft}/post")->assertUnprocessable()->assertJsonPath('code', 'credit_invoice_invalid');
    }

    // 6. Opening balances need the approve or manage permission and an early date.
    public function test_opening_balances_need_approval_rights_and_a_date_before_the_first_posting(): void
    {
        $clerk = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Bookkeeper', ['core.import.run', 'accounting.journal.create', 'accounting.journal.view']), Scope::company($this->acme->id));

            return $user;
        });
        $file = fn () => $this->csvFile([['Company', 'Date', 'Account code', 'Debit', 'Credit'], ['Acme', '2026-01-01', '1120', '100.00', '']]);
        $this->assertSame(0, $this->importFile('opening_balances', $file(), user: $clerk)['created_rows']);

        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $late = $this->importFile('opening_balances', $this->csvFile([['Company', 'Date', 'Account code', 'Debit', 'Credit'], ['Acme', '2026-10-20', '1120', '100.00', '']]));
        $this->assertSame(0, $late['created_rows']);
        $this->assertSame(1, $this->importFile('opening_balances', $file())['created_rows']);
    }

    // 7. Account roles and the control flag.
    public function test_control_flags_freeze_and_receivable_and_payable_roles_need_control_accounts_without_open_items(): void
    {
        $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $this->api('PATCH', 'accounting/accounts/'.$this->account('1200')->id, ['is_control' => false])->assertUnprocessable()->assertJsonPath('code', 'account_has_postings');
        $this->api('PUT', $this->companyUri('settings'), ['roles' => [['role' => 'receivable', 'account_id' => $this->account('1210')->id]]])
            ->assertUnprocessable()->assertJsonPath('code', 'role_needs_control');

        $newControl = $this->api('POST', $this->companyUri('accounts'), ['code' => '1205', 'name' => 'Receivables 2', 'type' => 'asset', 'subtype' => 'receivable', 'is_control' => true])->json('data.id');
        $this->api('PUT', $this->companyUri('settings'), ['roles' => [['role' => 'receivable', 'account_id' => $newControl]]])
            ->assertUnprocessable()->assertJsonPath('code', 'role_has_open_items');
        $this->api('PUT', $this->companyUri('settings'), ['roles' => [['role' => 'payable', 'account_id' => $this->account('2110')->id]]])->assertOk();
    }

    // 8. Allocation dates.
    public function test_allocations_default_to_today_and_refuse_dates_before_the_documents_or_in_closed_periods(): void
    {
        $invoice = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']]);
        $receipt = $this->api('POST', $this->companyUri('payments'), ['kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => '2026-10-12', 'amount' => '500.00'])->json('data');

        $this->api('POST', "accounting/payments/{$receipt['id']}/allocate", ['date' => '2026-10-09', 'allocations' => [['document_id' => $invoice['id'], 'amount' => '10.00']]])
            ->assertUnprocessable()->assertJsonPath('code', 'allocation_date');
        $this->api('POST', "accounting/payments/{$receipt['id']}/allocate", ['allocations' => [['document_id' => $invoice['id'], 'amount' => '10.00']]])
            ->assertOk()->assertJsonPath('data.allocations.0.date', '2026-10-15');

        $early = $this->postedDocument('customer_invoice', [['account' => '4100', 'price' => '100.00']], ['issue_date' => '2026-01-05']);
        $januaryReceipt = $this->api('POST', $this->companyUri('payments'), ['kind' => 'receipt', 'party_id' => $this->customer->id, 'bank_account_id' => $this->bank->id, 'date' => '2026-01-06', 'amount' => '5.00'])->json('data');
        $this->closeJanuary();
        $this->api('POST', "accounting/payments/{$januaryReceipt['id']}/allocate", ['date' => '2026-01-20', 'allocations' => [['document_id' => $early['id'], 'amount' => '5.00']]])
            ->assertUnprocessable()->assertJsonPath('code', 'period_closed');
    }

    // 9, 10. Statement lines repeated in one file, other encodings, and the GL account's currency.
    public function test_equal_lines_in_one_file_are_kept_windows_1252_is_read_and_utf16_is_refused(): void
    {
        $csv = "Date,Description,Reference,Amount\n2026-10-12,Frais caf\xE9,,-15.00\n2026-10-12,Frais caf\xE9,,-15.00\n";
        $send = fn (string $content) => $this->post("/api/v1/accounting/bank-accounts/{$this->bank->id}/statements", [
            'file' => UploadedFile::fake()->createWithContent('statement.csv', $content), 'format' => 'csv',
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ], [...$this->headersFor(), 'Accept' => 'application/json']);

        $send($csv)->assertCreated()->assertJsonPath('data.lines', 2);
        $send($csv)->assertCreated()->assertJsonPath('data.lines', 0)->assertJsonPath('data.skipped', 2);
        $this->inTenant(fn () => $this->assertSame(['Frais café', 'Frais café'], BankStatementLine::query()->pluck('description')->all()));
        $send("\xFF\xFE".mb_convert_encoding($csv, 'UTF-16LE', 'UTF-8'))->assertUnprocessable()->assertJsonPath('code', 'statement_unreadable');

        $this->api('POST', $this->companyUri('bank-accounts'), ['name' => 'Petty cash', 'kind' => 'cash', 'account_id' => $this->account('1110')->id, 'currency' => 'KES'])->assertCreated();
        $this->assertSame('KES', $this->account('1110')->currency);
    }

    // 11. Recurring journals are checked like journals and never far in the past.
    public function test_recurring_templates_are_checked_like_journals_and_not_dated_far_back(): void
    {
        $lines = fn (string $debit, string $code = '6200') => [['account_id' => $this->account($code)->id, 'debit' => $debit], ['account_id' => $this->account('1120')->id, 'credit' => '100.00']];
        $body = fn (array $extra) => ['name' => 'Rent', 'frequency' => 'monthly', 'next_run_on' => '2026-11-01', 'lines' => $lines('100.00'), ...$extra];

        $this->api('POST', $this->companyUri('recurring-journals'), $body(['lines' => $lines('100.00', '1200')]))->assertUnprocessable()->assertJsonPath('code', 'control_account');
        $this->api('POST', $this->companyUri('recurring-journals'), $body(['lines' => $lines('99.00')]))->assertUnprocessable()->assertJsonPath('code', 'journal_unbalanced');
        $this->api('POST', $this->companyUri('recurring-journals'), $body(['next_run_on' => '2026-06-01']))->assertUnprocessable()->assertJsonPath('code', 'recurring_stale');
        $this->api('POST', $this->companyUri('recurring-journals'), $body([]))->assertCreated();
    }

    // 12. A statement line posted, unmatched and posted again reuses its journal.
    public function test_a_line_posted_again_after_unmatching_reuses_its_journal(): void
    {
        $this->post("/api/v1/accounting/bank-accounts/{$this->bank->id}/statements", [
            'file' => UploadedFile::fake()->createWithContent('s.csv', "Date,Description,Reference,Amount\n2026-10-12,Charges,,-15.00\n"), 'format' => 'csv',
            'mapping' => ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference', 'amount' => 'Amount'],
        ], [...$this->headersFor(), 'Accept' => 'application/json'])->assertCreated();
        $line = $this->inTenant(fn () => BankStatementLine::query()->sole());

        $this->api('POST', "accounting/bank-statement-lines/{$line->id}/post", ['account_id' => $this->account('6400')->id])->assertOk();
        $this->api('POST', "accounting/bank-statement-lines/{$line->id}/unmatch")->assertOk();
        $this->api('POST', "accounting/bank-statement-lines/{$line->id}/post", ['account_id' => $this->account('6400')->id])->assertOk()->assertJsonPath('data.status', 'matched');
        $this->inTenant(fn () => $this->assertSame(1, Journal::query()->where('source_type', 'accounting.bank_line')->count()));
    }

    // 13. Every failure is kept; rounding only for foreign sources.
    public function test_the_posting_service_keeps_any_failure_and_rounds_only_foreign_sources(): void
    {
        $this->inTenant(fn () => PostingRule::create(['company_id' => $this->acme->id, 'event_type' => 'test.broken', 'lines' => [
            ['field' => 'total', 'side' => 'sideways', 'account_id' => $this->account('1110')->id], ['field' => 'total', 'side' => 'credit', 'account_id' => $this->account('4100')->id],
        ]]));
        $broken = $this->inTenant(fn () => app(PostingService::class)->post(new SourceDocument($this->acme->id, 'test.broken', (string) Str::uuid7(), CarbonImmutable::parse('2026-10-12T08:00:00Z'), 'KES', ['total' => '100'])));
        $this->assertSame('posting_failed', $broken->exception->error_code);

        $this->inTenant(fn () => PostingRule::create(['company_id' => $this->acme->id, 'event_type' => 'test.off', 'lines' => [
            ['field' => 'total', 'side' => 'debit', 'account_id' => $this->account('1110')->id], ['field' => 'net', 'side' => 'credit', 'account_id' => $this->account('4100')->id],
        ]]));
        $off = $this->inTenant(fn () => app(PostingService::class)->post(new SourceDocument($this->acme->id, 'test.off', (string) Str::uuid7(), CarbonImmutable::parse('2026-10-12T08:00:00Z'), 'KES', ['total' => '100', 'net' => '99'])));
        $this->assertSame('journal_unbalanced', $off->exception->error_code);
    }

    // 14. A foreign manual journal may carry a small, visible rounding line.
    public function test_a_foreign_manual_journal_carries_a_rounding_line_within_the_tolerance(): void
    {
        $this->rate('USD', 'KES', '129.5', at: '2026-09-01T00:00:00Z');
        // USD 1.01 at 129.5 = KES 130.795 → 13080; the KES side is 130.79.
        $ok = $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '1.01', null, ['currency' => 'USD']], ['1120', null, '130.79']]))->assertCreated()->json('data');
        $rounding = collect($ok['lines'])->firstWhere('account.code', '7200');
        $this->assertSame(['Rounding', '1'], [$rounding['description'], $rounding['credit']['amount_minor']]);

        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '1.01', null, ['currency' => 'USD']], ['1120', null, '130.70']]))
            ->assertUnprocessable()->assertJsonPath('code', 'journal_unbalanced');
    }

    // 15. Consolidation needs the permission at every company of the group.
    public function test_consolidation_needs_the_permission_at_every_company(): void
    {
        $this->inTenant(fn () => $this->company('Second company'));
        $partial = $this->inTenant(function () {
            $user = $this->colleague($this->owner);
            $this->assign($user, $this->role('Group reader', ['accounting.report.consolidate', 'accounting.report.view']), Scope::company($this->acme->id));

            return $user;
        });

        $this->api('GET', 'accounting/consolidation', [], $partial)->assertForbidden();
        $this->api('GET', 'accounting/consolidation?currency=KES')->assertOk();
    }
}
