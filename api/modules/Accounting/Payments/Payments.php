<?php

namespace Modules\Accounting\Payments;

use App\Core\Currency\BaseCurrencyLock;
use App\Core\Currency\Converter;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Money;
use App\Core\Currency\Rate;
use App\Core\Currency\RateUnavailable;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Numbering\NumberContext;
use App\Core\Numbering\Numbering;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Accounting\Chart\Charts;
use Modules\Accounting\Events\PaymentRecorded;
use Modules\Accounting\Ledger\JournalPoster;
use Modules\Accounting\Ledger\LedgerError;
use Modules\Accounting\Ledger\LineInput;
use Modules\Accounting\Ledger\LineParser;
use Modules\Accounting\Models\BankAccount;
use Modules\Accounting\Models\Document;
use Modules\Accounting\Models\Payment;

/**
 * ACC-10: receipts from customers and payments to suppliers, in the bank
 * account's currency, posted when recorded (bank against the control
 * account with the party) at the rate entered or the core rate on the
 * date (CUR-04), numbered (NUM-01), then allocated to open documents;
 * the rest stays on account.
 */
class Payments
{
    public const NUMBERING = [
        'receipt' => ['accounting.receipt', 'RCT-{YYYY}-{00001}'],
        'payment' => ['accounting.payment', 'PAY-{YYYY}-{00001}'],
    ];

    public const SOURCE = 'accounting.payment';

    public function __construct(
        private readonly CurrencyDecimals $decimals,
        private readonly Converter $converter,
        private readonly BaseCurrencyLock $baseLock,
        private readonly JournalPoster $poster,
        private readonly Numbering $numbering,
        private readonly Charts $charts,
        private readonly LineParser $parser,
        private readonly Allocations $allocations,
    ) {}

    /** @param array<string, mixed> $data the validated request */
    public function record(Company $company, array $data, User $by): Payment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($company, $data, $by) {
            $kind = $data['kind'];
            $role = $kind === 'receipt' ? 'customer' : 'supplier';
            $party = Party::query()->whereKey($data['party_id'])->where(fn ($q) => $q->whereNull('company_id')->orWhere('company_id', $company->id))->first();

            if ($party === null || $party->isArchived() || ! in_array($role, $party->roles ?? [], true)) {
                throw LedgerError::make("party_not_{$role}", field: 'party_id');
            }

            $bank = BankAccount::query()->where('company_id', $company->id)->whereNull('archived_at')->find($data['bank_account_id'])
                ?? throw LedgerError::make('bank_account_unknown', field: 'bank_account_id');
            $base = $this->baseLock->lock($company);
            $date = CarbonImmutable::parse($data['date']);
            $amount = Money::parse((string) $data['amount'], $bank->currency, $this->decimals);

            if ($amount->isZero() || $amount->isNegative()) {
                throw LedgerError::make('amount_positive', field: 'amount');
            }

            $fx = $this->rate($company, $bank->currency, $base, $date, $data['rate'] ?? null);
            $baseAmount = $fx->isIdentity() ? $amount : $fx->convert($amount);
            $control = $this->charts->accountFor($company->id, $role === 'customer' ? 'receivable' : 'payable');
            [$type] = self::NUMBERING[$kind];
            $number = $this->numbering->next($type, new NumberContext($company, at: $date->setTime(12, 0)));
            $paymentId = (string) Str::uuid7();
            $in = $kind === 'receipt';

            $journal = $this->poster->record($company, [
                'date' => $date,
                'kind' => 'system',
                'source_type' => self::SOURCE,
                'source_id' => $paymentId,
                'reference' => $number->number,
                'memo' => $data['memo'] ?? null,
                'created_by' => $by->id,
            ], [
                new LineInput($bank->account_id, $in ? LineInput::DEBIT : LineInput::CREDIT, $amount, $fx, $baseAmount, $data['reference'] ?? null),
                new LineInput($control, $in ? LineInput::CREDIT : LineInput::DEBIT, $amount, $fx, $baseAmount, $data['reference'] ?? null, ['party_id' => $party->id]),
            ], $by);

            $payment = new Payment([
                'company_id' => $company->id,
                'kind' => $kind,
                'party_id' => $party->id,
                'bank_account_id' => $bank->id,
                'number' => $number->number,
                'date' => $date->toDateString(),
                'currency' => $bank->currency,
                'amount_minor' => (int) $amount->minor(),
                'fx' => $fx,
                'base_currency' => $base,
                'base_amount_minor' => (int) $baseAmount->minor(),
                'unallocated_minor' => (int) $amount->minor(),
                'reference' => $data['reference'] ?? null,
                'memo' => $data['memo'] ?? null,
                'journal_id' => $journal->id,
                'created_by' => $by->id,
            ]);
            $payment->id = $paymentId;
            $payment->save();

            $this->allocate($payment, $data['allocations'] ?? [], $by, $date);
            PaymentRecorded::dispatch((string) $payment->tenant_id, $payment->id, $company->id, $kind);

            return $payment->refresh();
        });
    }

    /** @param list<array{document_id: string, amount: string}> $allocations */
    public function allocate(Payment $payment, array $allocations, User $by, ?CarbonImmutable $date = null): Payment
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($payment, $allocations, $by, $date) {
            // A later allocation is dated today in the company's time zone unless a date is given.
            $company = Company::query()->findOrFail($payment->company_id);
            foreach (array_values($allocations) as $index => $allocation) {
                $invoice = Document::query()->where('company_id', $payment->company_id)->find($allocation['document_id'])
                    ?? throw LedgerError::make('allocation_mismatch', field: "allocations.{$index}.document_id");
                $this->allocations->allocate($payment, $invoice, Money::parse((string) $allocation['amount'], $invoice->currency, $this->decimals), $date ?? CarbonImmutable::now($company->timezone ?: 'UTC')->startOfDay(), $by);
            }

            return $payment->refresh();
        });
    }

    private function rate(Company $company, string $currency, string $base, CarbonImmutable $date, mixed $rate): FxSnapshot
    {
        if ($currency === $base) {
            return FxSnapshot::identity($base);
        }

        $this->parser->assertPro('bank_account_id');

        if ($rate !== null && $rate !== '') {
            return new FxSnapshot(Rate::normalise((string) $rate), $currency, $base, 'manual', null);
        }

        try {
            return $this->converter->toBase(Money::ofMinor(0, $currency), $company, CarbonImmutable::parse($date->toDateString(), $company->timezone ?: 'UTC')->endOfDay())['snapshot'];
        } catch (RateUnavailable) {
            throw LedgerError::make('rate_unavailable', ['pair' => "{$currency}/{$base}", 'date' => $date->toDateString()], 'rate');
        }
    }
}
