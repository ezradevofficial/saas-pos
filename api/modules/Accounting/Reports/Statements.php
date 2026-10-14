<?php

namespace Modules\Accounting\Reports;

use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Journal;

/**
 * ACC-17: financial statements of one company, in base currency, from
 * posted journals only. Amounts are signed integers (minor units); a
 * debit balance is positive. Filters: a date range (`from` inclusive,
 * `to` inclusive) and dimensions (cost centre, department, project).
 *
 * - trialBalance: every account with an opening balance, the period's
 *   debits and credits and the closing balance; Σ debits = Σ credits,
 *   always (each posted journal balances, acc_journal_balanced).
 * - profitAndLoss: income and expense movements of the range, leaving out
 *   closing entries, grouped as revenue, cost of sales, expenses and other.
 * - balanceSheet: balances at `to`; earnings not yet closed to retained
 *   earnings are shown in equity, so assets = liabilities + equity.
 * - cashFlow (indirect): net profit, then each balance sheet account's
 *   movement by its cash-flow class (working capital and non-cash items
 *   in operating, investing, financing); the total is the change in cash.
 */
class Statements
{
    /** @param array{cost_centre_id?: ?string, department_id?: ?string, project_id?: ?string} $dimensions */
    public function trialBalance(Company $company, string $from, string $to, array $dimensions = []): array
    {
        $opening = $this->sums($company, null, $this->before($from), $dimensions)->keyBy('account_id');
        $movement = $this->sums($company, $from, $to, $dimensions)->keyBy('account_id');
        $rows = [];
        $totals = ['opening' => 0, 'debit' => 0, 'credit' => 0, 'closing' => 0];

        foreach ($this->accounts($company) as $account) {
            $open = (int) ($opening[$account->id]->debit ?? 0) - (int) ($opening[$account->id]->credit ?? 0);
            $debit = (int) ($movement[$account->id]->debit ?? 0);
            $credit = (int) ($movement[$account->id]->credit ?? 0);

            if ($open === 0 && $debit === 0 && $credit === 0) {
                continue;
            }

            $rows[] = $this->account($account) + ['opening' => $open, 'debit' => $debit, 'credit' => $credit, 'closing' => $open + $debit - $credit];
            $totals['opening'] += $open;
            $totals['debit'] += $debit;
            $totals['credit'] += $credit;
            $totals['closing'] += $open + $debit - $credit;
        }

        return ['currency' => $company->base_currency, 'from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals, 'balanced' => $totals['debit'] === $totals['credit'] && $totals['closing'] === 0 && $totals['opening'] === 0];
    }

    public function profitAndLoss(Company $company, string $from, string $to, array $dimensions = []): array
    {
        $movement = $this->sums($company, $from, $to, $dimensions, withoutClosing: true)->keyBy('account_id');
        $sections = ['revenue' => [], 'cost_of_sales' => [], 'expenses' => [], 'other_income' => [], 'other_expenses' => []];

        foreach ($this->accounts($company)->whereIn('type', ['income', 'expense']) as $account) {
            $net = (int) ($movement[$account->id]->debit ?? 0) - (int) ($movement[$account->id]->credit ?? 0);

            if ($net === 0) {
                continue;
            }

            $section = match (true) {
                $account->type === 'income' && $account->subtype === 'revenue' => 'revenue',
                $account->type === 'income' => 'other_income',
                $account->subtype === 'cost_of_sales' => 'cost_of_sales',
                in_array($account->subtype, ['fx'], true) => 'other_expenses',
                default => 'expenses',
            };
            // Income shows as a positive amount (credit balance), expenses as positive costs.
            $sections[$section][] = $this->account($account) + ['amount' => $account->type === 'income' ? -$net : $net];
        }

        $sum = fn (string $key) => array_sum(array_column($sections[$key], 'amount'));
        $gross = $sum('revenue') - $sum('cost_of_sales');
        $operating = $gross - $sum('expenses');
        $net = $operating + $sum('other_income') - $sum('other_expenses');

        return [
            'currency' => $company->base_currency, 'from' => $from, 'to' => $to,
            'sections' => array_map(fn (array $rows) => ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))], $sections),
            'gross_profit' => $gross, 'operating_profit' => $operating, 'net_profit' => $net,
        ];
    }

    public function balanceSheet(Company $company, string $to, array $dimensions = []): array
    {
        $balances = $this->sums($company, null, $to, $dimensions)->keyBy('account_id');
        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        $earnings = 0;

        foreach ($this->accounts($company) as $account) {
            $balance = (int) ($balances[$account->id]->debit ?? 0) - (int) ($balances[$account->id]->credit ?? 0);

            if ($balance === 0) {
                continue;
            }

            if (! $account->isBalanceSheet()) {
                $earnings -= $balance;

                continue;
            }

            $sections[$account->type][] = $this->account($account) + ['amount' => $account->type === 'asset' ? $balance : -$balance];
        }

        $assets = array_sum(array_column($sections['asset'], 'amount'));
        $liabilities = array_sum(array_column($sections['liability'], 'amount'));
        $equity = array_sum(array_column($sections['equity'], 'amount')) + $earnings;

        return [
            'currency' => $company->base_currency, 'to' => $to,
            'sections' => array_map(fn (array $rows) => ['rows' => $rows, 'total' => array_sum(array_column($rows, 'amount'))], $sections),
            'current_earnings' => $earnings,
            'total_assets' => $assets, 'total_liabilities' => $liabilities, 'total_equity' => $equity,
            'balanced' => $assets === $liabilities + $equity,
        ];
    }

    public function cashFlow(Company $company, string $from, string $to): array
    {
        $movement = $this->sums($company, $from, $to, [], withoutClosing: true)->keyBy('account_id');
        $opening = $this->sums($company, null, $this->before($from))->keyBy('account_id');
        $groups = ['operating' => [], 'non_cash' => [], 'investing' => [], 'financing' => []];
        $netProfit = 0;
        $cashChange = 0;
        $openingCash = 0;

        foreach ($this->accounts($company) as $account) {
            $net = (int) ($movement[$account->id]->debit ?? 0) - (int) ($movement[$account->id]->credit ?? 0);

            if (! $account->isBalanceSheet()) {
                $netProfit -= $net;

                continue;
            }

            if ($account->cash_flow === 'cash') {
                $cashChange += $net;
                $openingCash += (int) ($opening[$account->id]->debit ?? 0) - (int) ($opening[$account->id]->credit ?? 0);

                continue;
            }

            if ($net !== 0) {
                $groups[$account->cash_flow ?? 'operating'][] = $this->account($account) + ['amount' => -$net];
            }
        }

        $total = fn (string $key) => array_sum(array_column($groups[$key], 'amount'));
        $operating = $netProfit + $total('non_cash') + $total('operating');

        return [
            'currency' => $company->base_currency, 'from' => $from, 'to' => $to,
            'net_profit' => $netProfit,
            'non_cash' => ['rows' => $groups['non_cash'], 'total' => $total('non_cash')],
            'working_capital' => ['rows' => $groups['operating'], 'total' => $total('operating')],
            'operating' => $operating,
            'investing' => ['rows' => $groups['investing'], 'total' => $total('investing')],
            'financing' => ['rows' => $groups['financing'], 'total' => $total('financing')],
            'net_change' => $operating + $total('investing') + $total('financing'),
            'opening_cash' => $openingCash,
            'closing_cash' => $openingCash + $cashChange,
            'reconciles' => $operating + $total('investing') + $total('financing') === $cashChange,
        ];
    }

    /**
     * Movements per account in a range (null from: from the start), posted
     * journals only, optionally without closing entries.
     *
     * @return Collection<int, object{account_id: string, debit: int, credit: int}>
     */
    public function sums(Company $company, ?string $from, ?string $to, array $dimensions = [], bool $withoutClosing = false): Collection
    {
        $query = $this->lines($company, $from, $to, $dimensions);

        if ($withoutClosing) {
            $query->where('acc_journals.kind', '<>', 'closing');
        }

        return $query->groupBy('acc_journal_lines.account_id')
            ->selectRaw('acc_journal_lines.account_id, coalesce(sum(acc_journal_lines.base_debit_minor), 0) as debit, coalesce(sum(acc_journal_lines.base_credit_minor), 0) as credit')
            ->get();
    }

    /** Posted lines of the company in a range, with dimension filters. */
    public function lines(Company $company, ?string $from, ?string $to, array $dimensions = []): Builder
    {
        $query = DB::table('acc_journal_lines')
            ->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
            ->where('acc_journals.company_id', $company->id)
            ->where('acc_journals.status', Journal::POSTED);

        if ($from !== null) {
            $query->where('acc_journals.date', '>=', $from);
        }

        if ($to !== null) {
            $query->where('acc_journals.date', '<=', $to);
        }

        foreach (['cost_centre_id', 'department_id', 'project_id', 'party_id'] as $key) {
            if (($dimensions[$key] ?? null) !== null) {
                $query->where("acc_journal_lines.{$key}", $dimensions[$key]);
            }
        }

        return $query;
    }

    /** @return Collection<int, Account> */
    public function accounts(Company $company): Collection
    {
        return Account::query()->where('company_id', $company->id)->orderBy('code')->get();
    }

    /** @return array{account: array{id: string, code: string, name: string, type: string, subtype: string}} */
    private function account(Account $account): array
    {
        return ['account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'type' => $account->type, 'subtype' => $account->subtype]];
    }

    private function before(string $from): string
    {
        return CarbonImmutable::parse($from)->subDay()->toDateString();
    }
}
