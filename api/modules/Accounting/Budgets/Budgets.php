<?php

namespace Modules\Accounting\Budgets;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\Money;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Ledger\DimensionCheck;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Budget;
use Modules\Accounting\Models\BudgetLine;

/**
 * ACC-16: budget cells (account, optional cost centre, month 1–12 of the
 * fiscal year) in base currency. Saving sets the cells given: an amount
 * writes the cell, an empty amount clears it. Every save is audited with
 * the cells before and after.
 */
class Budgets
{
    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly Auditor $auditor,
    ) {}

    /** @param list<array{account_id: string, cost_centre_id?: ?string, month: int, amount: ?string}> $cells */
    public function setCells(Budget $budget, array $cells): Budget
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($budget, $cells) {
            $budget = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            if ($budget->isArchived()) {
                throw LedgerError::make('budget_archived');
            }

            $company = Company::query()->findOrFail($budget->company_id);
            $accounts = Account::query()->where('company_id', $company->id)->whereKey(array_column($cells, 'account_id'))->pluck('id')->all();
            DimensionCheck::assert($company, array_map(fn (array $cell) => ['cost_centre_id' => $cell['cost_centre_id'] ?? null], $cells), 'cells');
            $changes = [];

            foreach (array_values($cells) as $index => $cell) {
                if (! in_array($cell['account_id'], $accounts, true)) {
                    throw LedgerError::make('account_unknown', field: "cells.{$index}.account_id");
                }

                $key = ['budget_id' => $budget->id, 'account_id' => $cell['account_id'], 'cost_centre_id' => $cell['cost_centre_id'] ?? null, 'month' => (int) $cell['month']];
                $existing = BudgetLine::query()->where($key)->first();
                $amount = ($cell['amount'] ?? null) === null || $cell['amount'] === '' ? null : (int) Money::parse((string) $cell['amount'], (string) $company->base_currency, $this->decimals)->minor();

                if ($amount === null) {
                    if ($existing !== null) {
                        $changes[] = [...$key, 'before' => $existing->amount_minor, 'after' => null];
                        $existing->delete();
                    }

                    continue;
                }

                $changes[] = [...$key, 'before' => $existing?->amount_minor, 'after' => $amount];
                $existing === null ? BudgetLine::query()->create([...$key, 'amount_minor' => $amount]) : $existing->fill(['amount_minor' => $amount])->save();
            }

            $this->auditor->record('accounting.budget.cells', $budget, null, ['cells' => $changes]);

            return $budget;
        });
    }
}
