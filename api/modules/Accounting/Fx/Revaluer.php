<?php

namespace Modules\Accounting\Fx;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Converter;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Ledger\Periods;
use Modules\Accounting\Ledger\Reversals;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\Allocation;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Journal;
use Modules\Accounting\Models\JournalLine;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Models\Period;
use Modules\Accounting\Models\Revaluation;

/**
 * ACC-15: unrealised FX at a period's end.
 *
 * Items: posted documents and unallocated receipts and payments in a
 * foreign currency, open at the period's last day (allocations dated up
 * to then), and foreign-currency accounts flagged `revalue` (their
 * balance in the currency and in base). Each item's open amount is
 * converted at the closing rate (the core rate at the end of that day,
 * integer arithmetic rounded half up through FxSnapshot::convert); the
 * adjustment is the new base value minus the base value carried (the
 * item's own rate; earlier revaluations were reversed). Adjustments post
 * as one revaluation journal dated the last day (the item's account
 * against FX gain or loss), reversed on the next period's first day, so
 * realised FX is still measured from the original rate. One per period.
 */
class Revaluer
{
    public const SOURCE = 'accounting.revaluation';

    public function __construct(
        private readonly Converter $converter,
        private readonly BaseCurrencyLock $baseLock,
        private readonly JournalPoster $poster,
        private readonly Reversals $reversals,
        private readonly Periods $periods,
        private readonly Charts $charts,
        private readonly LineParser $parser,
    ) {}

    public function revalue(Period $period, User $by): Revaluation
    {
        $this->parser->assertPro();

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($period, $by) {
            $period = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($period->isClosed()) {
                throw LedgerError::make('period_closed', ['date' => $period->ends_on->toDateString()]);
            }

            if (Revaluation::query()->where('period_id', $period->id)->exists()) {
                throw LedgerError::make('revaluation_exists');
            }

            $company = Company::query()->findOrFail($period->company_id);
            $base = $this->baseLock->lock($company);
            $asOf = $period->ends_on;
            $at = CarbonImmutable::parse($asOf->toDateString(), $company->timezone ?: 'UTC')->endOfDay();
            $rates = [];
            $items = [];

            foreach ($this->openItems($company, $base, $asOf) as $item) {
                $fx = $rates[$item['currency']] ??= $this->closingRate($company, $item['currency'], $at);
                $new = $this->signedBase($fx, $item['open'], $item['currency']);
                $adjustment = $new - $item['carried'];

                if ($adjustment !== 0) {
                    $items[] = [...$item, 'new' => $new, 'adjustment' => $adjustment];
                }
            }

            $revaluation = Revaluation::query()->create([
                'company_id' => $company->id,
                'period_id' => $period->id,
                'as_of' => $asOf->toDateString(),
                'rates' => array_map(fn (FxSnapshot $fx) => $fx->jsonSerialize(), $rates),
                'items' => $items,
                'run_by' => $by->id,
            ]);

            if ($items === []) {
                return $revaluation;
            }

            $journal = $this->poster->record($company, [
                'date' => $asOf,
                'kind' => 'revaluation',
                'source_type' => self::SOURCE,
                'source_id' => $revaluation->id,
                'memo' => __('accounting.revaluation.memo', ['date' => $asOf->toDateString()]),
                'created_by' => $by->id,
            ], $this->lines($company, $base, $items, $rates), $by);

            $next = $this->periods->forDate($company, $asOf->addDay());
            $reversal = $this->reversals->reverse($journal, $next->starts_on, $by, __('accounting.revaluation.reversal_memo', ['date' => $asOf->toDateString()]));
            $revaluation->fill(['journal_id' => $journal->id, 'reversal_journal_id' => $reversal->id])->save();

            return $revaluation;
        });
    }

    /**
     * Foreign-currency items open at $asOf: `open` in their currency and
     * `carried` in base, both signed as a debit balance of `account_id`.
     *
     * @return list<array{type: string, id: string, label: string, account_id: string, party_id: ?string, currency: string, open: int, carried: int}>
     */
    public function openItems(Company $company, string $base, CarbonImmutable $asOf): array
    {
        $items = [];
        $date = $asOf->toDateString();
        $receivable = $this->charts->accountFor($company->id, 'receivable');
        $payable = $this->charts->accountFor($company->id, 'payable');

        $documents = Document::query()->where('company_id', $company->id)->where('status', Document::POSTED)
            ->where('currency', '<>', $base)->where('issue_date', '<=', $date)->get();

        foreach ($documents as $document) {
            $settled = $document->isCredit()
                ? (int) Allocation::query()->where('credit_document_id', $document->id)->where('date', '<=', $date)->sum('amount_minor')
                : (int) Allocation::query()->where('document_id', $document->id)->where('date', '<=', $date)->sum('amount_minor');
            $open = $document->total_minor - $settled;

            if ($open <= 0) {
                continue;
            }

            // A customer invoice and a supplier credit are debit balances; the others credit balances.
            $sign = in_array($document->kind, ['customer_invoice', 'supplier_credit'], true) ? 1 : -1;
            $items[] = [
                'type' => 'document', 'id' => $document->id, 'label' => (string) $document->number,
                'account_id' => $document->side() === 'customer' ? $receivable : $payable, 'party_id' => $document->party_id,
                'currency' => $document->currency, 'open' => $sign * $open, 'carried' => $sign * $this->signedBase($document->fx, $open, $document->currency),
            ];
        }

        $payments = Payment::query()->where('company_id', $company->id)->where('currency', '<>', $base)->where('date', '<=', $date)->get();

        foreach ($payments as $payment) {
            $open = $payment->amount_minor - (int) Allocation::query()->where('payment_id', $payment->id)->where('date', '<=', $date)->sum('amount_minor');

            if ($open <= 0) {
                continue;
            }

            // An unallocated receipt is a credit on the receivable; an unallocated payment a debit on the payable.
            $sign = $payment->kind === 'receipt' ? -1 : 1;
            $items[] = [
                'type' => 'payment', 'id' => $payment->id, 'label' => $payment->number,
                'account_id' => $payment->kind === 'receipt' ? $receivable : $payable, 'party_id' => $payment->party_id,
                'currency' => $payment->currency, 'open' => $sign * $open, 'carried' => $sign * $this->signedBase($payment->fx, $open, $payment->currency),
            ];
        }

        $accounts = Account::query()->where('company_id', $company->id)->where('postable', true)->where('is_control', false)
            ->whereNotNull('currency')->where('currency', '<>', $base)->where('revalue', true)->get();

        foreach ($accounts as $account) {
            $sums = JournalLine::query()->join('acc_journals', 'acc_journals.id', '=', 'acc_journal_lines.journal_id')
                ->where('acc_journals.status', Journal::POSTED)->where('acc_journals.date', '<=', $date)
                ->where('acc_journal_lines.account_id', $account->id)
                ->selectRaw('coalesce(sum(acc_journal_lines.debit_minor - acc_journal_lines.credit_minor), 0) as open, coalesce(sum(acc_journal_lines.base_debit_minor - acc_journal_lines.base_credit_minor), 0) as carried')
                ->first();

            if ((int) $sums->open === 0 && (int) $sums->carried === 0) {
                continue;
            }

            $items[] = [
                'type' => 'account', 'id' => $account->id, 'label' => $account->code, 'account_id' => $account->id, 'party_id' => null,
                'currency' => $account->currency, 'open' => (int) $sums->open, 'carried' => (int) $sums->carried,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, FxSnapshot>  $rates
     * @return list<LineInput>
     */
    private function lines(Company $company, string $base, array $items, array $rates): array
    {
        $lines = [];
        $net = 0;

        foreach ($items as $item) {
            $adjustment = (int) $item['adjustment'];
            $net += $adjustment;
            $lines[] = new LineInput($item['account_id'], $adjustment > 0 ? LineInput::DEBIT : LineInput::CREDIT, Money::ofMinor(0, $item['currency']),
                $rates[$item['currency']], Money::ofMinor((string) abs($adjustment), $base), __('accounting.revaluation.line', ['item' => $item['label']]), ['party_id' => $item['party_id']]);
        }

        $gains = array_sum(array_filter(array_column($items, 'adjustment'), fn (int $a) => $a > 0));
        $losses = -array_sum(array_filter(array_column($items, 'adjustment'), fn (int $a) => $a < 0));

        if ($gains > 0) {
            $lines[] = new LineInput($this->charts->accountFor($company->id, 'fx_gain'), LineInput::CREDIT, Money::ofMinor((string) $gains, $base), FxSnapshot::identity($base));
        }

        if ($losses > 0) {
            $lines[] = new LineInput($this->charts->accountFor($company->id, 'fx_loss'), LineInput::DEBIT, Money::ofMinor((string) $losses, $base), FxSnapshot::identity($base));
        }

        return $lines;
    }

    /** $amount (signed minor units of $currency) in base at $fx, rounded half up on its magnitude. */
    private function signedBase(?FxSnapshot $fx, int $amount, string $currency): int
    {
        if ($fx === null || $fx->isIdentity()) {
            return $amount;
        }

        $value = (int) $fx->convert(Money::ofMinor((string) abs($amount), $currency))->minor();

        return $amount < 0 ? -$value : $value;
    }

    private function closingRate(Company $company, string $currency, CarbonImmutable $at): FxSnapshot
    {
        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, $at)['snapshot'];
        } catch (RateUnavailable) {
            throw LedgerError::make('rate_unavailable', ['pair' => "{$currency}/{$company->base_currency}", 'date' => $at->toDateString()]);
        }
    }
}
