<?php

namespace Modules\Accounting\Reports;

use App\Core\Currency\Converter;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Currency\RateUnavailable;
use App\Core\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Models\Account;

/**
 * ACC-18, TEN-07: the group's statements across companies of the tenant,
 * in a reporting currency (decision 6).
 *
 * - Each company's balances are translated with its own rates from its
 *   base to the reporting currency: balance sheet accounts at the closing
 *   rate (the `to` date), profit and loss movements month by month at each
 *   month end's rate. What this leaves unbalanced (assets + intercompany −
 *   liabilities − equity − translated earnings) is the company's
 *   translation reserve, shown in equity.
 * - Closing entries are left out throughout, so a year's profit shows as
 *   profit and not again in retained earnings.
 * - Intercompany accounts (an account naming another company of the group)
 *   are eliminated: their translated balances leave assets and liabilities;
 *   whatever does not cancel across the group is the elimination
 *   difference, shown in equity, never hidden.
 */
class Consolidation
{
    public function __construct(
        private readonly Statements $statements,
        private readonly Converter $converter,
        private readonly ExchangeRates $rates,
    ) {}

    /** @param Collection<int, Company> $companies */
    public function report(Collection $companies, string $currency, string $from, string $to): array
    {
        $ids = $companies->pluck('id')->all();
        $group = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
        $eliminated = ['assets' => 0, 'liabilities' => 0];
        $intercompany = 0;
        $reserve = 0;
        $members = [];

        foreach ($companies as $company) {
            $closing = $this->rate($company, $currency, $to);
            $accounts = Account::query()->where('company_id', $company->id)->get()->keyBy('id');
            $sections = ['asset' => 0, 'liability' => 0, 'equity' => 0, 'income' => 0, 'expense' => 0];
            $companyIntercompany = 0;

            foreach ($this->statements->sums($company, null, $to, [], withoutClosing: true) as $row) {
                $account = $accounts[$row->account_id] ?? null;

                if ($account === null) {
                    continue;
                }

                $debitBalance = $this->translate($company, (int) $row->debit - (int) $row->credit, $currency, $closing);

                if (! $account->isBalanceSheet()) {
                    // Earnings of earlier periods not closed into equity are added below with the range's.
                    continue;
                }

                if ($account->counterparty_company_id !== null && $account->counterparty_company_id !== $company->id && in_array($account->counterparty_company_id, $ids, true)) {
                    $companyIntercompany += $debitBalance;
                    $debitBalance > 0 ? $eliminated['assets'] += $debitBalance : $eliminated['liabilities'] -= $debitBalance;

                    continue;
                }

                $sections[$account->type] += $account->type === 'asset' ? $debitBalance : -$debitBalance;
            }

            // Earnings before the range, not yet closed into equity, at the closing rate.
            $earlier = 0;

            foreach ($this->statements->sums($company, null, CarbonImmutable::parse($from)->subDay()->toDateString(), [], withoutClosing: true) as $row) {
                $account = $accounts[$row->account_id] ?? null;

                if ($account !== null && ! $account->isBalanceSheet()) {
                    $earlier += (int) $row->credit - (int) $row->debit;
                }
            }

            $sections['equity'] += $this->translate($company, $earlier, $currency, $closing);

            // The range's profit and loss, month by month at each month end's rate.
            $earnings = 0;

            for ($month = CarbonImmutable::parse($from)->startOfMonth(); $month->toDateString() <= $to; $month = $month->addMonthNoOverflow()) {
                $end = min($month->endOfMonth()->toDateString(), $to);
                $rate = $this->rate($company, $currency, $end);

                foreach ($this->statements->sums($company, max($month->toDateString(), $from), $end, [], withoutClosing: true) as $row) {
                    $account = $accounts[$row->account_id] ?? null;

                    if ($account === null || $account->isBalanceSheet()) {
                        continue;
                    }

                    $amount = $this->translate($company, (int) $row->credit - (int) $row->debit, $currency, $rate);
                    $account->type === 'income' ? $sections['income'] += $amount : $sections['expense'] -= $amount;
                    $earnings += $amount;
                }
            }

            $translationReserve = $sections['asset'] + $companyIntercompany - $sections['liability'] - $sections['equity'] - $earnings;
            $intercompany += $companyIntercompany;
            $reserve += $translationReserve;

            foreach ($sections as $key => $amount) {
                $group[$key] += $amount;
            }

            $members[] = [
                'company' => ['id' => $company->id, 'name' => $company->name, 'base_currency' => $company->base_currency],
                'closing_rate' => $closing === null ? null : ['rate' => $closing->mid, 'base' => $closing->base, 'quote' => $closing->quote],
                'sections' => $sections,
                'net_profit' => $earnings,
                'intercompany' => $companyIntercompany,
                'translation_reserve' => $translationReserve,
            ];
        }

        $netProfit = $group['income'] - $group['expense'];
        // The intercompany balances that do not cancel stay in the group's equity as the elimination difference.
        $difference = $intercompany;
        $equity = $group['equity'] + $reserve + $difference + $netProfit;

        return [
            'currency' => $currency, 'from' => $from, 'to' => $to,
            'companies' => $members,
            'sections' => $group,
            'eliminations' => ['assets' => $eliminated['assets'], 'liabilities' => $eliminated['liabilities'], 'difference' => $difference],
            'translation_reserve' => $reserve,
            'net_profit' => $netProfit,
            'total_equity' => $equity,
            'balanced' => $group['asset'] === $group['liability'] + $equity,
        ];
    }

    /** The rate translating $company's base into $currency at the end of $date; null for the same currency. */
    private function rate(Company $company, string $currency, string $date): ?Rate
    {
        if ($company->base_currency === $currency) {
            return null;
        }

        try {
            return $this->rates->stored($company, $company->base_currency, $currency, CarbonImmutable::parse($date, $company->timezone ?: 'UTC')->endOfDay());
        } catch (RateUnavailable) {
            throw LedgerError::make('rate_unavailable', ['pair' => "{$company->base_currency}/{$currency}", 'date' => $date], 'currency');
        }
    }

    /** Signed $minor of the company's base in $currency at $rate (half up on the magnitude, ADR 003). */
    private function translate(Company $company, int $minor, string $currency, ?Rate $rate): int
    {
        if ($rate === null || $minor === 0) {
            return $minor;
        }

        $value = (int) $this->converter->convert(Money::ofMinor((string) abs($minor), $company->base_currency), $currency, $rate)->minor();

        return $minor < 0 ? -$value : $value;
    }
}
