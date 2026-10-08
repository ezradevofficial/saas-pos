<?php

namespace Modules\POS\Sync;

use App\Core\Audit\Auditor;
use App\Core\Currency\CurrencyDecimals;
use App\Core\Currency\FxSnapshot;
use App\Core\Currency\Models\TenantCurrency;
use App\Core\Currency\Money;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\POS\Events\SaleRefunded;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;
use Modules\POS\Models\Shift;

/**
 * POS-05: lines or quantities of a sale given back at the till
 * (idempotent by the refund's id), a new record referencing the sale.
 *
 * - The sale is this location's and completed; a line is never refunded
 *   beyond its quantity sold.
 * - The server computes each line's refund: the line total × qty / qty
 *   sold, half up; the last part of a line takes what is left, so a line
 *   refunded in parts adds up to its total exactly. The till's total must
 *   match, and the money returned must add up to it.
 * - Needs `pos.sale.refund` within `max_refund_amount` (RBAC-06, compared
 *   in the company's base currency, major units, at the sale's own rate)
 *   for the person, or a manager's override (AUTH-08); refused otherwise.
 * - Numbered from the device's `pos.refund` range (NUM-02). Audited;
 *   raises SaleRefunded after commit.
 */
class RefundUploads
{
    public function __construct(
        private readonly Authority $authority,
        private readonly NumberRanges $ranges,
        private readonly CurrencyDecimals $decimals,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
    ) {}

    /** @return list<array<string, mixed>> */
    public function upload(DevicePlace $place, array $refunds): array
    {
        return array_map(function (array $data) use ($place) {
            $existing = Refund::query()->find($data['id']);

            if ($existing !== null) {
                return $existing->device_id === $place->device->id
                    ? UploadResults::refund($existing)
                    : UploadResults::rejected($data['id'], new Rejection('id_conflict', 'id'));
            }

            try {
                return UploadResults::refund(DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($place, $data)));
            } catch (Rejection $rejection) {
                return UploadResults::rejected($data['id'], $rejection);
            } catch (UniqueConstraintViolationException $e) {
                $refund = Refund::query()->find($data['id']);

                return $refund !== null && $refund->device_id === $place->device->id
                    ? UploadResults::refund($refund)
                    : UploadResults::rejected($data['id'], new Rejection(NumberRanges::isReuse($e) ? 'receipt_number_used' : 'id_conflict', NumberRanges::isReuse($e) ? 'receipt_seq' : 'id'));
            }
        }, $refunds);
    }

    private function store(DevicePlace $place, array $data): Refund
    {
        $at = CarbonImmutable::parse($data['refunded_at'])->utc();
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

        $cashier = $this->authority->user($data['cashier_id'], 'cashier_id');
        [$lines, $total, $tax] = $this->lines($sale, $data['lines']);

        if ((string) $data['total_minor'] !== (string) $total) {
            throw new Rejection('refund_total_mismatch', 'total_minor');
        }

        $snapshot = $sale->fx;
        $baseTotal = array_reduce($lines, fn (BigDecimal $sum, array $line) => $sum->plus($snapshot->convert(Money::ofMinor((string) $line['total'], $sale->currency))->minor()), BigDecimal::zero());
        $baseMajor = BigDecimal::ofUnscaledValue((string) $baseTotal, $this->decimals->for($sale->base_currency));

        $approval = $this->authority->approve($cashier, $data['override'] ?? null, 'pos.sale.refund', $place->scope(),
            fn ($user) => $this->authority->within($user, 'max_refund_amount', $place->scope(), $baseMajor),
            $place->device, $data['id'], 'override');

        $payments = $this->payments($place, $data['payments'], $sale, $total);
        $range = $this->ranges->claim($place, 'pos.refund', (int) $data['receipt_seq'], $data['receipt_number'], $at, 'receipt');

        $refund = Refund::create([
            'id' => $data['id'],
            'sale_id' => $sale->id,
            ...$place->columns(),
            'shift_id' => $shift->id,
            'cashier_id' => $cashier->id,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
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
            $line['sale_line']->forceFill(['refunded_qty' => (string) BigDecimal::of($line['sale_line']->refunded_qty)->plus($line['qty'])])->save();
        }

        foreach ($payments as $payment) {
            RefundPayment::create(['refund_id' => $refund->id, ...$payment]);
        }

        $this->auditor->record('pos.sale.refund', $sale, null, [
            'refund_id' => $refund->id,
            'receipt_number' => $refund->receipt_number,
            'total' => Money::ofMinor((string) $total, $sale->currency),
            'lines' => array_map(fn (array $line) => ['line' => $line['sale_line']->line_no, 'qty' => $line['qty']], $lines),
            'reason' => $refund->reason,
            'approved_by' => $approval->approverId(),
            'override_verified' => $approval->verified,
        ], ['user_id' => $cashier->id, 'on_behalf_of_user_id' => $approval->approverId(), 'device_time' => $at]);
        SaleRefunded::dispatch($this->tenants->require(), $sale->id, $refund->id);

        return $refund;
    }

    /**
     * @return array{0: list<array{id: string, sale_line: SaleLine, qty: string, tax: BigDecimal, total: BigDecimal}>, 1: BigDecimal, 2: BigDecimal}
     */
    private function lines(Sale $sale, array $requested): array
    {
        $saleLines = SaleLine::query()->where('sale_id', $sale->id)->lockForUpdate()->get()->keyBy('id');
        $asked = [];
        $lines = [];
        $total = BigDecimal::zero();
        $tax = BigDecimal::zero();

        foreach ($requested as $index => $line) {
            $saleLine = $saleLines->get($line['sale_line_id']) ?? throw new Rejection('sale_line_unknown', "lines.{$index}.sale_line_id");
            $qty = BigDecimal::of((string) $line['qty']);
            $asked[$saleLine->id] = ($asked[$saleLine->id] ?? BigDecimal::zero())->plus($qty);
            $sold = BigDecimal::of($saleLine->qty);
            $already = BigDecimal::of($saleLine->refunded_qty);
            $left = $sold->minus($already)->minus($asked[$saleLine->id]);

            if ($left->isNegative()) {
                throw new Rejection('refund_qty_exceeded', "lines.{$index}.qty");
            }

            if ($left->isZero()) {
                // The last part takes what is left of the line's total and tax.
                $previous = RefundLine::query()->where('sale_line_id', $saleLine->id)->selectRaw('coalesce(sum(total_minor), 0) as total, coalesce(sum(tax_minor), 0) as tax')->first();
                $earlier = array_reduce($lines, fn (array $carry, array $l) => $l['sale_line']->id === $saleLine->id ? [$carry[0]->plus($l['total']), $carry[1]->plus($l['tax'])] : $carry, [BigDecimal::zero(), BigDecimal::zero()]);
                $lineTotal = BigDecimal::of($saleLine->total_minor)->minus((string) $previous->total)->minus($earlier[0]);
                $lineTax = BigDecimal::of($saleLine->tax_minor)->minus((string) $previous->tax)->minus($earlier[1]);
            } else {
                $lineTotal = BigDecimal::of($saleLine->total_minor)->multipliedBy($qty)->dividedBy($sold, 0, RoundingMode::HalfUp);
                $lineTax = BigDecimal::of($saleLine->tax_minor)->multipliedBy($qty)->dividedBy($sold, 0, RoundingMode::HalfUp);
            }

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
     * The money returned, per method and currency, adding up to the refund
     * (one minor unit of tolerance per tender in another currency).
     *
     * @return list<array<string, mixed>>
     */
    private function payments(DevicePlace $place, array $payments, Sale $sale, BigDecimal $total): array
    {
        $rows = [];
        $sum = BigDecimal::zero();
        $tolerance = 0;

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
            $snapshot = Amounts::snapshot($payment['rate'] ?? null, $payment['currency'], $sale->currency, "{$field}.rate");
            $exact = $snapshot === null ? BigDecimal::of($amount->minor()) : Amounts::exact($amount, $snapshot);

            if ($exact->minus($inSale)->abs()->isGreaterThanOrEqualTo(1)) {
                throw new Rejection('payment_conversion_mismatch', "{$field}.amount_in_sale_minor");
            }

            $tolerance += $snapshot === null ? 0 : 1;
            $sum = $sum->plus($inSale);
            $rows[] = [
                'id' => $payment['id'],
                'payment_method_id' => $method->id,
                'method_type' => $method->type,
                'currency' => $amount->currency(),
                'amount_minor' => $amount->minor(),
                'amount_in_sale_minor' => (string) $inSale,
                'fx' => $snapshot instanceof FxSnapshot ? $snapshot : null,
                'provider_reference' => $payment['provider_reference'] ?? null,
                'status' => $payment['status'] ?? SalePayment::CONFIRMED,
            ];
        }

        if ($sum->minus($total)->abs()->isGreaterThan($tolerance)) {
            throw new Rejection('refund_payments_mismatch', 'payments');
        }

        return $rows;
    }
}
