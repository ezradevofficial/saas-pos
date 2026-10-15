<?php

namespace Modules\Accounting\Tests;

use App\Core\MasterData\Dimensions\Department;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Support\EventReplays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Integrations\Integrations;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\PostingException;
use Modules\Accounting\Models\PostingRule;
use Modules\Payroll\Tests\Concerns\BuildsPayroll;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// PAY-22, ACC-07, ACC-24: a posted payroll run posts one balanced
// `payroll.run` journal per department and pay currency through the KE
// default rule (gross pay expense with the department, employer
// contributions as expense and liability, PAYE and statutory deductions
// payable, net pay payable), idempotently; a correction posts its
// differences; nothing posts without Accounting.
class PayrollPostingTest extends TestCase
{
    use BuildsPayroll, RefreshTenantDatabase;

    private Department $kitchen;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->setUpPayroll();
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(Charts::class)->fromTemplate($this->acme);
            $this->kitchen = Department::create(['company_id' => $this->acme->id, 'code' => 'KITCHEN', 'name' => 'Kitchen']);
        });
    }

    /** @return array<string, array{0: int, 1: int}> by account code: [base debit, base credit] */
    private function linesOf(Journal $journal): array
    {
        return $this->inTenant(function () use ($journal) {
            $lines = [];

            foreach (JournalLine::query()->where('journal_id', $journal->id)->get() as $line) {
                $code = Account::query()->whereKey($line->account_id)->value('code');
                $lines[$code] = [($lines[$code][0] ?? 0) + (int) $line->base_debit_minor, ($lines[$code][1] ?? 0) + (int) $line->base_credit_minor];
            }

            ksort($lines);

            return $lines;
        });
    }

    /** @return list<Journal> */
    private function journals(): array
    {
        return $this->inTenant(fn () => Journal::query()->where('source_type', 'payroll.run')->where('status', 'posted')->orderBy('created_at')->get()->all());
    }

    public function test_a_posted_run_posts_balanced_journals_per_department(): void
    {
        foreach (['Amina', 'Baraka', 'Chao'] as $name) {
            $this->payrollEmployee('5000000', ['first_name' => $name]);
        }

        $this->payrollEmployee('3000000', ['first_name' => 'Juma', 'department_id' => $this->kitchen->id]);
        $id = $this->postedRun();

        $journals = $this->journals();
        $this->assertCount(2, $journals);
        $department = fn (Journal $j) => $this->inTenant(fn () => JournalLine::query()->where('journal_id', $j->id)->whereNotNull('department_id')->value('department_id'));
        $sales = collect($journals)->first(fn (Journal $j) => $department($j) === $this->sales->id);

        $this->assertSame([
            '2220' => [0, 2820000],
            '2230' => [0, 1215000],
            '2240' => [0, 11430000],
            '6100' => [15000000, 0],
            '6110' => [465000, 0],
        ], $this->linesOf($sales));
        // ACC-33: each line names its component (the pack's label, or the run's own words).
        $descriptions = $this->inTenant(fn () => JournalLine::query()->where('journal_id', $sales->id)->get()
            ->map(fn (JournalLine $line) => Account::query()->whereKey($line->account_id)->value('code').' '.($line->debit_minor > 0 ? 'Dr' : 'Cr').' '.$line->description)->sort()->values()->all());
        $this->assertSame([
            '2220 Cr PAYE', '2230 Cr Affordable Housing Levy', '2230 Cr Affordable Housing Levy employer share', '2230 Cr NITA levy employer share',
            '2230 Cr NSSF', '2230 Cr NSSF employer share', '2230 Cr SHIF', '2240 Cr Net pay payable', '6100 Dr Gross pay',
            '6110 Dr Affordable Housing Levy employer share', '6110 Dr NITA levy employer share', '6110 Dr NSSF employer share',
        ], $descriptions);

        // payroll.md decision 41: Kitchen pays one employee, so its journal is at company level (no department).
        $this->assertNotNull(collect($journals)->first(fn (Journal $j) => $department($j) === null));

        foreach ($journals as $journal) {
            $lines = $this->linesOf($journal);
            $this->assertSame(array_sum(array_column($lines, 0)), array_sum(array_column($lines, 1)));
            $this->assertEquals(['kind' => 'payroll.run', 'id' => $id], $journal->source_link);
        }

        // Posting the same run again (a replay) adds nothing.
        $this->inTenant(function () {
            app(EventReplays::class)->replay(['payroll'], CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDay(), fn (object $event) => app(Integrations::class)->handle($event));
        });
        $this->assertCount(2, $this->journals());
    }

    public function test_a_reversal_correction_posts_the_journal_reversed(): void
    {
        $amina = $this->payrollEmployee('5000000');
        $october = $this->postedRun();
        $correction = $this->postJson("/api/v1/payroll/runs/{$october}/corrections", ['mode' => 'reverse', 'pay_date' => '2026-10-30'], $this->headersFor($this->officer))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$correction}/calculate", [], $this->headersFor($this->officer))->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$correction}/submit", [], $this->headersFor($this->officer))->assertOk();
        $this->approve($correction)->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$correction}/post", [], $this->headersFor($this->officer))->assertOk();

        $journals = $this->journals();
        $this->assertCount(2, $journals);
        $this->assertSame([
            '2220' => [940000, 0],
            '2230' => [405000, 0],
            '2240' => [3810000, 0],
            '6100' => [0, 5000000],
            '6110' => [0, 155000],
        ], $this->linesOf($journals[1]));
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()));
        $this->assertNotNull($amina);
    }

    public function test_nothing_posts_without_accounting(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('accounting'));
        $this->payrollEmployee('5000000');
        $this->postedRun();

        $this->assertSame([], $this->journals());
        $this->assertSame(0, $this->inTenant(fn () => PostingException::query()->count()));
    }

    public function test_posting_exceptions_of_a_run_name_their_department(): void
    {
        // ACC-33: one source per department; with no rule, each fails on its own and the list says which department.
        $this->inTenant(fn () => PostingRule::query()->where('event_type', 'payroll.run')->update(['archived_at' => now()]));
        $this->payrollEmployee('5000000');
        $this->payrollEmployee('3000000', ['first_name' => 'Juma', 'department_id' => $this->kitchen->id]);
        $this->postedRun();

        $rows = $this->getJson("/api/v1/companies/{$this->acme->id}/accounting/posting-exceptions", $this->headersFor($this->owner))->assertOk()->json('data');
        $this->assertSame(['Kitchen', 'Sales'], collect($rows)->pluck('department.name')->sort()->values()->all());
    }
}
