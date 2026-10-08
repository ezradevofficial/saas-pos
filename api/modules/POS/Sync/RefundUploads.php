<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\ExchangeRates;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Money;
use App\Core\Currency\RateUnavailable;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;
use Modules\POS\Models\Shift;

/**
 * POS-05: lines or quantities of a sale given back at the till
 * (idempotent by the refund's id; other content under the same id is
 * `payload_mismatch`), a new record referencing the sale.
 *
 * - The sale is this location's and completed; a line is never refunded
 *   beyond its quantity sold, counting refunds applied or waiting.
 * - Each line's refund is allocated cumulatively: refunding q after p
 *   gives round(total × (p + q) / sold) − round(total × p / sold), half up,
 *   so parts always add up to the line exactly. The till's total must
 *   match; the money returned must add up to it (within one minor unit of
 *   the exact converted sum).
 * - H1: money returned in another currency is converted at the sale's own
 *   rate for that currency (its tender's rate, else the sale's base rate,
 *   else the server's rate then), never at a rate the till chose; a till
 *   amount that doesn't match is held with `refund_rate_differs`.
 * - Needs `pos.sale.refund` within `max_refund_amount` (RBAC-06, in the
 *   company's base currency, major units, at the sale's rate) for the
 *   person, or a manager's override (AUTH-08); refused otherwise. An
 *   approval that can't be proven is **held** (H2, H3): nothing changes
 *   and nothing is raised until it is approved in the back office.
 * - Numbered from the device's `pos.refund` range (NUM-02).
 */
class RefundUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly NumberRanges $ranges,
        private readonly CurrencyDecimals $decimals,
        private readonly ExchangeRates $rates,
        private readonly HeldRecords $records,
        private readonly Auditor $auditor,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $refunds): array
    {
        return array_map(function (array $data) use ($place) {
            $hash = Records::hash($data);
            $existing = $this->existing($place, $data['id'], $hash);

            if ($existing !== null) {
                return $existing;
            }

            try {
                return UploadResults::refund(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data, $hash)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException $e) {
                return $this->existing($place, $data['id'], $hash)
                    ?? UploadResults::rejected($data['id'], new Rejection(NumberRanges::isReuse($e) ? 'receipt_number_used' : 'id_conflict', NumberRanges::isReuse($e) ? 'receipt_seq' : 'id'));
            }
        }, $refunds);
    }

    private function existing(DevicePlace $place, string $id, string $hash): ?array
    {
        $refund = Refund::query()->find($id);

        return match (true) {
            $refund === null => null,
            $refund->device_id !== $place->device->id => UploadResults::rejected($id, new Rejection('id_conflict', 'id')),
            $refund->payload_hash !== $hash => UploadResults::rejected($id, new Rejection('payload_mismatch', 'id')),
            default => UploadResults::refund($refund),
        };
    }

    private function store(DevicePlace $place, array $data, string $hash): Refund
    {
        $at = CarbonImmutable::parse($data['refunded_at'])->utc();
        $flags = new Flags;
        $sale = Sale::query()->whereKey($data['sale_id'])->lockForUpdate()->first() ?? throw new Rejection('sale_unknown', 'sale_id', retryable: true);

        if ($sale->location_id !== $place->location->id) {
            throw new Rejection('sale_other_location', 'sale_id');
        }

        if ($sale->status !== Sale::COMPLETED) {
            throw new Rejection('sale_already_voided', 'sale_id');
        }

        $shift = Shift::query()->find($data['shift_id']) ?? throw new Rejection('shift_unknown', 'shift_id', retryable: true);

        if ($shift->device_id !== $place->device->id) {
            throw new Rejection('shift_other_device', 'shift_id');
        }

        if (! $shift->isOpen()) {
            $flags->add('received_after_close');
        }

        $cashier = $this->authority->user($data['cashier_id'], 'cashier_id');
        [$lines, $total, $tax] = $this->lines($sale, $data['lines']);

        if ((string) $data['total_minor'] !== (string) $total) {
            throw new Rejection('refund_total_mismatch', 'total_minor');
        }

        $snapshot = $sale->fx;
        $baseTotal = array_reduce($lines, fn (BigDecimal $sum, array $line) => $sum->plus($snapshot->convert(Money::ofMinor((string) $line['total'], $sale->currency))->minor()), BigDecimal::zero());
        $baseMajor = BigDecimal::ofUnscaledValue((string) $baseTotal, $this->decimals->for($sale->base_currency));

        $this->authority->checkNamed($data['override'] ?? null, 'override');
        $payments = $this->payments($place, $data['payments'], $sale, $total, $at, $flags);
        $approval = $this->authority->approve($cashier, $data['override'] ?? null, $data['actor_proof'] ?? null, 'pos.sale.refund', $place->scope(),
            fn ($user) => $this->authority->within($user, 'max_refund_amount', $place->scope(), $baseMajor),
            $place->device, $data['id'], 'override');

        if ($approval->held()) {
            $flags->add($approval->flag());
        }

        $held = $approval->held() || in_array('refund_rate_differs', array_column($flags->all(), 'code'), true);
        $range = $this->ranges->claim($place, 'pos.refund', (int) $data['receipt_seq'], $data['receipt_number'], $at, 'receipt', $data['number_range_id'] ?? null);

        $refund = Refund::create([
            'id' => $data['id'],
            'sale_id' => $sale->id,
            ...$place->columns(),
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
            'status' => Records::HELD,
            'flags' => $flags->all(),
            'payload_hash' => $hash,
            'number_range_id' => $range->id,
            'receipt_seq' => (int) $data['receipt_seq'],
            'receipt_number' => $data['receipt_number'],
            'reason' => $data['reason'],
            'currency' => $sale->currency,
            'tax_minor' => (string) $tax,
            'total_minor' => (string) $total,
            'base_currency' => $sale->base_currency,
            'base_total_minor' => (string) $baseTotal,
            'fx' => $snapshot,
            'refunded_at' => $at,
            'received_at' => now(),
        ]);

        foreach ($lines as $line) {
            RefundLine::create(['id' => $line['id'], 'refund_id' => $refund->id, 'sale_line_id' => $line['sale_line']->id, 'qty' => $line['qty'], 'tax_minor' => (string) $line['tax'], 'total_minor' => (string) $line['total']]);
        }

        foreach ($payments as $payment) {
            RefundPayment::create(['refund_id' => $refund->id, ...$payment]);
        }

        if ($held) {
            $this->auditor->record('pos.sale.refund_hold', $sale, null, [
                'refund_id' => $refund->id, 'receipt_number' => $refund->receipt_number, 'total' => Money::ofMinor((string) $total, $sale->currency),
                'approved_by' => $approval->approverId(), 'flags' => $refund->flags,
            ], ['user_id' => $cashier->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);
        } else {
            $this->records->applyRefund($refund);
        }

        return $refund;
    }

    /**
     * @return array{0: list<array{id: string, sale_line: SaleLine, qty: string, tax: BigDecimal, total: BigDecimal}>, 1: BigDecimal, 2: BigDecimal}
     */
    private function lines(Sale $sale, array $requested): array
    {
        $saleLines = SaleLine::query()->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
        $lines = [];
        $total = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $taken = [];

        foreach ($requested as $index => $line) {
            $saleLine = $saleLines->get($line['sale_line_id']) ?? throw new Rejection('sale_line_unknown', "lines.{$index}.sale_line_id");
            $qty = BigDecimal::of((string) $line['qty']);
            $sold = BigDecimal::of($saleLine->qty);
            // Quantities already given back, applied or waiting, then earlier lines of this refund.
            $before = $taken[$saleLine->id] ??= BigDecimal::of((string) RefundLine::query()->where('sale_line_id', $saleLine->id)
                ->whereIn('refund_id', Refund::query()->where('status', '<>', Records::REJECTED)->select('id'))->sum('qty'));
            $after = $before->plus($qty);

            if ($after->isGreaterThan($sold)) {
                throw new Rejection('refund_qty_exceeded', "lines.{$index}.qty");
            }

            $share = fn (string $amount, BigDecimal $part) => BigDecimal::of($amount)->multipliedBy($part)->dividedBy($sold, 0, RoundingMode::HalfUp);
            $lineTotal = $share($saleLine->total_minor, $after)->minus($share($saleLine->total_minor, $before));
            $lineTax = $share($saleLine->tax_minor, $after)->minus($share($saleLine->tax_minor, $before));
            $taken[$saleLine->id] = $after;

            $lines[] = ['id' => $line['id'], 'sale_line' => $saleLine, 'qty' => (string) $qty, 'tax' => $lineTax, 'total' => $lineTotal];
            $total = $total->plus($lineTotal);
            $tax = $tax->plus($lineTax);
        }

        if (! $total->isPositive()) {
            throw new Rejection('refund_total_mismatch', 'total_minor');
        }

        return [$lines, $total, $tax];
    }

    /**
     * The money returned, per method and currency, at the sale's own rates
     * (H1). The till's amounts in the sale currency must add up to the
     * refund within one minor unit of their exact sum; a conversion other
     * than the sale's rate gives flags `refund_rate_differs` (the refund
     * is then held).
     *
     * @return list<array<string, mixed>>
     */
    private function payments(DevicePlace $place, array $payments, Sale $sale, BigDecimal $total, CarbonImmutable $at, Flags $flags): array
    {
        $rows = [];
        $exactSum = BigDecimal::zero();
        $tillSum = BigDecimal::zero();

        foreach ($payments as $index => $payment) {
            $field = "payments.{$index}";
            $method = PaymentMethod::query()->find($payment['payment_method_id']);

            if ($method === null || $method->company_id !== $place->company->id) {
                throw new Rejection('payment_method_unknown', "{$field}.payment_method_id");
            }

            if ($method->currency !== null && $method->currency !== $payment['currency']) {
                throw new Rejection('payment_currency_mismatch', "{$field}.currency");
            }

            if (! TenantCurrency::query()->where('code', $payment['currency'])->exists()) {
                throw new Rejection('currency_unknown', "{$field}.currency");
            }

            $amount = Money::ofMinor((string) $payment['amount_minor'], $payment['currency']);
            $inSale = BigDecimal::of((string) $payment['amount_in_sale_minor']);
            $snapshot = $this->saleRate($place, $sale, $payment['currency'], $at, "{$field}.currency");
            $exact = $snapshot === null ? BigDecimal::of($amount->minor()) : Amounts::exact($amount, $snapshot);

            if ($exact->minus($inSale)->abs()->isGreaterThanOrEqualTo(1)) {
                $flags->add('refund_rate_differs', null, ['payment' => $index + 1, 'expected_in_sale_minor' => (string) $exact->toScale(0, RoundingMode::HalfUp)]);
            }

            $exactSum = $exactSum->plus($exact);
            $tillSum = $tillSum->plus($inSale);
            $rows[] = [
                'id' => $payment['id'],
                'payment_method_id' => $method->id,
                'method_type' => $method->type,
                'currency' => $amount->currency(),
                'amount_minor' => $amount->minor(),
                'amount_in_sale_minor' => (string) $exact->toScale(0, RoundingMode::HalfUp),
                'fx' => $snapshot,
                'provider_reference' => $payment['provider_reference'] ?? null,
                'status' => $payment['status'] ?? SalePayment::CONFIRMED,
            ];
        }

        // The till's conversions differ from the sale's rates: its own sums must still add up (one
        // minor unit per tender), and the refund then waits for review. Otherwise the exact sum at the
        // sale's rates must be within one minor unit of the refund.
        $differs = in_array('refund_rate_differs', array_column($flags->all(), 'code'), true);
        $sum = $differs ? $tillSum : $exactSum;

        if ($sum->minus($total)->abs()->isGreaterThan($differs ? count($rows) : '0.999999')) {
            throw new Rejection('refund_payments_mismatch', 'payments');
        }

        return $rows;
    }

    /** The sale's rate between $currency and its own currency: its tender's, its base rate, else the server's at the refund. */
    private function saleRate(DevicePlace $place, Sale $sale, string $currency, CarbonImmutable $at, string $field): ?FxSnapshot
    {
        if ($currency === $sale->currency) {
            return null;
        }

        $tendered = SalePayment::query()->where('sale_id', $sale->id)->where('currency', $currency)->whereNotNull('fx_rate')->orderBy('created_at')->first()?->fx;

        if ($tendered !== null) {
            return $tendered;
        }

        if (in_array($currency, [$sale->fx->base, $sale->fx->quote], true) && ! $sale->fx->isIdentity()) {
            return $sale->fx;
        }

        try {
            return FxSnapshot::fromRate($this->rates->stored($place->company, $currency, $sale->currency, $at));
        } catch (RateUnavailable) {
            throw new Rejection('rate_unavailable', $field, retryable: true);
        }
    }
}
