<?php

namespace Modules\Accounting\Tests;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Currency\FxSnapshot;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Accounting\Posting\PostingService;
use Modules\Accounting\Posting\SourceDocument;
use Modules\Accounting\Tests\Concerns\BuildsAccounting;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/** ACC-03 to ACC-08: the ledger's database rules, posting rules, manual journals and reversals. */
class LedgerTest extends TestCase
{
    use BuildsAccounting, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccounting();
    }

    private function salesRule(): void
    {
        $this->inTenant(fn () => PostingRule::create(['company_id' => $this->acme->id, 'event_type' => 'test.sale', 'lines' => [
            ['field' => 'total', 'side' => 'debit', 'account_id' => $this->account('1110')->id, 'party' => false],
            ['field' => 'net', 'side' => 'credit', 'account_id' => $this->account('4100')->id, 'party' => false],
            ['field' => 'tax', 'side' => 'credit', 'account_id' => $this->account('2200')->id, 'party' => false],
        ]]));
    }

    private function source(?string $id = null, array $amounts = ['total' => '112500', 'net' => '100000', 'tax' => '12500'], string $date = '2026-10-12T10:00:00Z', ?FxSnapshot $fx = null, string $currency = 'KES'): SourceDocument
    {
        return new SourceDocument($this->acme->id, 'test.sale', $id ?? (string) Str::uuid7(), CarbonImmutable::parse($date), $currency, $amounts, $fx, reference: 'R-0001');
    }

    public function test_a_posted_journal_that_does_not_balance_is_refused_by_the_database(): void
    {
        $this->inTenant(function () {
            $period = app(Periods::class)->forDate($this->acme, CarbonImmutable::parse('2026-10-12'));
            $journal = Journal::create(['company_id' => $this->acme->id, 'date' => '2026-10-12', 'period_id' => $period->id, 'kind' => 'manual', 'status' => 'draft', 'base_currency' => 'KES']);
            $line = fn (string $code, int $debit, int $credit, int $no) => JournalLine::create([
                'company_id' => $this->acme->id, 'journal_id' => $journal->id, 'line_no' => $no, 'account_id' => $this->account($code)->id, 'currency' => 'KES',
                'debit_minor' => $debit, 'credit_minor' => $credit, 'base_debit_minor' => $debit, 'base_credit_minor' => $credit,
            ]);
            $line('1110', 1000, 0, 1);
            $line('4100', 0, 999, 2);

            try {
                DB::transaction(function () use ($journal) {
                    // A raw update, bypassing every check in PHP.
                    DB::update("update acc_journals set status = 'posted', posted_at = now() where id = ?", [$journal->id]);
                    DB::statement('set constraints acc_journals_balanced, acc_journal_lines_balanced immediate');
                });
                $this->fail('An unbalanced journal was posted.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_journal_unbalanced', $e->getMessage());
            }

            $this->assertSame('draft', $journal->refresh()->status);
        });
    }

    public function test_posted_journals_are_immutable_in_the_database(): void
    {
        $this->salesRule();
        $journal = $this->inTenant(fn () => app(PostingService::class)->post($this->source())->journal);

        $this->inTenant(function () use ($journal) {
            foreach ([
                fn () => DB::update('update acc_journal_lines set debit_minor = 5 where journal_id = ?', [$journal->id]),
                fn () => DB::delete('delete from acc_journal_lines where journal_id = ?', [$journal->id]),
                fn () => DB::update("update acc_journals set date = '2026-10-01' where id = ?", [$journal->id]),
                fn () => DB::update("update acc_journals set status = 'draft', posted_at = null where id = ?", [$journal->id]),
                fn () => DB::delete('delete from acc_journals where id = ?', [$journal->id]),
            ] as $change) {
                try {
                    DB::transaction($change);
                    $this->fail('A posted journal was changed.');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('acc_journal_immutable', $e->getMessage());
                }
            }
        });
    }

    public function test_posting_a_source_is_idempotent_and_balanced_in_base_currency(): void
    {
        $this->salesRule();
        $id = (string) Str::uuid7();

        [$first, $second] = $this->inTenant(fn () => [app(PostingService::class)->post($this->source($id)), app(PostingService::class)->post($this->source($id))]);

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->journal->id, $second->journal->id);
        $this->inTenant(function () use ($first, $id) {
            $this->assertSame(1, Journal::query()->where('source_type', 'test.sale')->where('source_id', $id)->count());
            $lines = JournalLine::query()->where('journal_id', $first->journal->id)->get();
            $this->assertSame(112500, $lines->sum('base_debit_minor'));
            $this->assertSame(112500, $lines->sum('base_credit_minor'));
            $this->assertStringStartsWith('JV-2026-', $first->journal->number);
            $this->assertSame('system', $first->journal->kind);
        });
    }

    public function test_a_source_without_a_rule_is_kept_and_posts_on_retry(): void
    {
        $id = (string) Str::uuid7();
        $result = $this->inTenant(fn () => app(PostingService::class)->post($this->source($id)));

        $this->assertFalse($result->posted());
        $this->assertSame('posting_rule_missing', $result->exception->error_code);

        $this->salesRule();
        $exception = $result->exception;
        $retried = $this->api('POST', "accounting/posting-exceptions/{$exception->id}/retry")->assertOk();
        $this->assertSame('posted', $retried->json('data.status'));
        $this->inTenant(fn () => $this->assertSame(PostingException::RESOLVED, $exception->refresh()->status));
    }

    public function test_a_foreign_source_uses_its_own_rate_and_rounds_into_the_rounding_account(): void
    {
        $this->inTenant(fn () => PostingRule::create(['company_id' => $this->acme->id, 'event_type' => 'test.sale', 'lines' => [
            ['field' => 'total', 'side' => 'debit', 'account_id' => $this->account('1110')->id],
            ['field' => 'a', 'side' => 'credit', 'account_id' => $this->account('4100')->id],
            ['field' => 'b', 'side' => 'credit', 'account_id' => $this->account('4200')->id],
        ]]));
        // USD 1.01 = 0.505 + 0.505 at 129.5: each half rounds up to KES 65.40 (6540) while the total is KES 130.80 (13080)
        $fx = new FxSnapshot('129.50000000', 'USD', 'KES', 'shop', null);
        $result = $this->inTenant(fn () => app(PostingService::class)->post($this->source(amounts: ['total' => '101', 'a' => '50', 'b' => '51'], fx: $fx, currency: 'USD')));

        $this->assertTrue($result->posted());
        $this->inTenant(function () use ($result) {
            $lines = JournalLine::query()->where('journal_id', $result->journal->id)->get();
            $this->assertSame($lines->sum('base_debit_minor'), $lines->sum('base_credit_minor'));
            $this->assertSame('129.50000000', (string) $lines->first()->fx->rate);
        });
    }

    public function test_a_journal_dated_in_a_closed_period_is_refused_through_the_api_and_the_database(): void
    {
        $this->inTenant(function () {
            $period = app(Periods::class)->forDate($this->acme, CarbonImmutable::parse('2026-01-15'));
            $period->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
        });
        $this->salesRule();

        $result = $this->inTenant(fn () => app(PostingService::class)->post($this->source(date: '2026-01-15T10:00:00Z')));
        $this->assertSame('period_closed', $result->exception->error_code);

        // The database refuses a posted journal in a closed period whatever the code path.
        $this->inTenant(function () {
            $period = Period::query()->where('company_id', $this->acme->id)->where('starts_on', '2026-01-01')->sole();

            try {
                DB::transaction(fn () => Journal::create(['company_id' => $this->acme->id, 'date' => '2026-01-15', 'period_id' => $period->id, 'kind' => 'manual',
                    'status' => 'posted', 'posted_at' => now(), 'base_currency' => 'KES']));
                $this->fail('Posted into a closed period.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('acc_period_closed', $e->getMessage());
            }
        });
    }

    public function test_a_manual_journal_is_approved_by_another_accountant_then_posted_and_can_be_reversed_once(): void
    {
        $body = $this->journalBody('2026-10-12', [['6200', '15000.00', null], ['1120', null, '15000.00']]);
        $draft = $this->api('POST', $this->companyUri('journals'), $body, $this->accountant)->assertCreated()->json('data');
        $this->assertSame('draft', $draft['status']);
        $this->assertSame('1500000', $draft['total']['amount_minor']);

        $approver = $this->userWith('accountant', Scope::company($this->acme->id));
        $this->api('POST', "accounting/journals/{$draft['id']}/submit", [], $this->accountant)->assertOk()->assertJsonPath('data.status', 'pending');
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_type', 'accounting.journal')->where('document_id', $draft['id'])->sole());

        // APR-07: the author cannot approve their own journal.
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($this->accountant))->assertStatus(403);
        $this->postJson("/api/v1/approvals/{$approval->id}/approve", [], $this->headersFor($approver))->assertOk();

        $posted = $this->api('GET', "accounting/journals/{$draft['id']}")->assertOk()->json('data');
        $this->assertSame('posted', $posted['status']);
        $this->assertStringStartsWith('JV-2026-', $posted['number']);

        $reversal = $this->api('POST', "accounting/journals/{$draft['id']}/reverse", ['date' => '2026-10-14'], $this->accountant)->assertCreated()->json('data');
        $this->assertSame('reversal', $reversal['kind']);
        $this->assertSame('1500000', $reversal['lines'][0]['credit']['amount_minor'] ?: $reversal['lines'][1]['credit']['amount_minor']);
        $this->api('POST', "accounting/journals/{$draft['id']}/reverse", [], $this->accountant)->assertUnprocessable()->assertJsonPath('code', 'journal_reversed');
        $this->inTenant(fn () => $this->assertSame(1, AuditEntry::query()->where('action', 'accounting.journal.reverse')->count()));
    }

    public function test_manual_journals_refuse_control_accounts_unbalanced_lines_and_foreign_currency_without_pro(): void
    {
        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['1200', '100.00', null], ['4100', null, '100.00']]))
            ->assertUnprocessable()->assertJsonPath('code', 'control_account');
        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '100.00', null], ['1120', null, '99.99']]))
            ->assertUnprocessable()->assertJsonPath('code', 'journal_unbalanced');

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting_pro'));
        $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '100.00', null, ['currency' => 'USD', 'rate' => '129.5']], ['1120', null, '12950.00']]))
            ->assertUnprocessable()->assertJsonPath('code', 'accounting_pro_required');
    }

    public function test_a_journal_rejected_in_its_flow_goes_back_to_its_author_and_a_draft_can_be_cancelled(): void
    {
        $draft = $this->api('POST', $this->companyUri('journals'), $this->journalBody('2026-10-12', [['6200', '10.00', null], ['1120', null, '10.00']]), $this->accountant)->json('data');
        $this->api('POST', "accounting/journals/{$draft['id']}/submit", [], $this->accountant)->assertOk();
        $approval = $this->inTenant(fn () => ApprovalRequest::query()->where('document_id', $draft['id'])->sole());
        $this->postJson("/api/v1/approvals/{$approval->id}/reject", ['comment' => 'Wrong account'], $this->headersFor($this->owner))->assertOk();

        $this->api('GET', "accounting/journals/{$draft['id']}")->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.editable', true);
        $this->api('POST', "accounting/journals/{$draft['id']}/cancel", ['reason' => 'Duplicate'], $this->accountant)->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_journals_need_their_permission_at_the_company_and_another_tenant_sees_nothing(): void
    {
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $auditor = $this->userWith('read_only_auditor', Scope::company($this->acme->id));
        $body = $this->journalBody('2026-10-12', [['6200', '10.00', null], ['1120', null, '10.00']]);

        $this->api('GET', $this->companyUri('journals'), [], $cashier)->assertNotFound();
        $this->api('GET', $this->companyUri('journals'), [], $auditor)->assertOk();
        $this->api('POST', $this->companyUri('journals'), $body, $auditor)->assertForbidden();

        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('accounting'));
        $this->getJson("/api/v1/companies/{$this->acme->id}/accounting/journals", $this->headersFor($other['user']))->assertNotFound();
        $journal = $this->api('POST', $this->companyUri('journals'), $body)->json('data.id');
        $this->getJson("/api/v1/accounting/journals/{$journal}", $this->headersFor($other['user']))->assertNotFound();
    }
}
