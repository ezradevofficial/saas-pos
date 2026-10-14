<?php

namespace Modules\POS\Events;

use App\Core\Currency\FxSnapshot;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;

/**
 * POS-13, LOY-27: what a stored sale or refund was, as plain data for the
 * modules listening to SaleCompleted, SaleVoided and SaleRefunded
 * (Loyalty, Inventory, Accounting), so they never read POS tables
 * (CLAUDE.md, modular monolith). Amounts are minor-unit digit strings,
 * quantities decimal strings, times ISO 8601 in UTC; other modules' data
 * (`extensions`, `adjustments`, `tender`) is passed on as the till sent it.
 *
 * Since stage 7B (Accounting integrations) a snapshot is complete enough
 * to journal from: per line the tax code, whether the price included tax,
 * the net, the tax and the discount split by where it came from
 * (`discount.sources`: what a registered discount source such as a
 * promotion vouched for, as the till recorded it, capped at the line's
 * discount; `discount.manual_minor`: the rest); the sale's rounding kept,
 * the change (in its currency and as its share of the sale currency, so
 * paid − change = total + rounding exactly) and its base currency, rate
 * and base amounts (CUR-04). A refund carries the same per-line fields
 * (each line's share of its sale line, as refunded) and its own rounding
 * (what the payments differ from the refund total by).
 */
final class SaleSnapshot
{
    /** @return array<string, mixed> */
    public static function sale(Sale $sale): array
    {
        $sale->loadMissing(['lines', 'payments']);
        $total = (string) $sale->total_minor;
        $paid = (string) $sale->paid_minor;
        $rounding = (string) $sale->rounding_minor;

        return [
            'id' => $sale->id,
            'company_id' => $sale->company_id,
            'branch_id' => $sale->branch_id,
            'location_id' => $sale->location_id,
            'device_id' => $sale->device_id,
            'shift_id' => $sale->shift_id,
            'cashier_id' => $sale->cashier_id,
            'customer_id' => $sale->customer_id,
            'receipt_number' => $sale->receipt_number,
            'status' => $sale->status,
            'sold_at' => self::time($sale->sold_at),
            'voided_at' => $sale->voided_at === null ? null : self::time($sale->voided_at),
            'offline' => (bool) $sale->offline,
            'currency' => $sale->currency,
            'totals' => [
                'subtotal_minor' => (string) $sale->subtotal_minor,
                'discount_minor' => (string) $sale->discount_minor,
                'tax_minor' => (string) $sale->tax_minor,
                'total_minor' => $total,
                'paid_minor' => $paid,
                // CUR-06: what the shop kept from rounding the change, in the sale currency.
                'rounding_minor' => $rounding,
            ],
            // CUR-06: change given, in its own currency, and its share of the sale currency.
            'change' => [
                'currency' => $sale->change_currency,
                'amount_minor' => (string) $sale->change_minor,
                'in_sale_minor' => (string) BigInteger::of($paid)->minus($total)->minus($rounding),
            ],
            // CUR-04: the base currency, the rate used and the base amounts.
            'base' => [
                'currency' => $sale->base_currency,
                'total_minor' => (string) $sale->base_total_minor,
                'tax_minor' => (string) $sale->base_tax_minor,
                'fx' => $sale->fx?->jsonSerialize(),
            ],
            'lines' => $sale->lines->map(fn (SaleLine $line) => [
                'id' => $line->id,
                'line_no' => (int) $line->line_no,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
                'qty' => self::qty((string) $line->qty),
                'unit_price_minor' => (string) $line->unit_price_minor,
                'discount_minor' => (string) $line->discount_minor,
                'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                'tax_inclusive' => (bool) $line->tax_inclusive,
                'net_minor' => (string) $line->net_minor,
                'tax_minor' => (string) $line->tax_minor,
                'total_minor' => (string) $line->total_minor,
                'discount' => self::discount((string) $line->discount_minor, $line->adjustments),
                'adjustments' => $line->adjustments,
            ])->values()->all(),
            'payments' => $sale->payments->map(fn (SalePayment $payment) => self::payment($payment))->values()->all(),
            'extensions' => $sale->extensions,
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(Refund $refund): array
    {
        $refund->loadMissing(['lines', 'payments', 'sale']);
        $saleLines = SaleLine::query()->whereKey($refund->lines->pluck('sale_line_id')->all())->get()->keyBy('id');
        $paid = $refund->payments->reduce(fn (BigInteger $sum, RefundPayment $p) => $sum->plus((string) $p->amount_in_sale_minor), BigInteger::zero());

        return [
            'id' => $refund->id,
            'sale_id' => $refund->sale_id,
            'company_id' => $refund->company_id,
            'branch_id' => $refund->branch_id,
            'location_id' => $refund->location_id,
            'customer_id' => $refund->sale?->customer_id,
            'receipt_number' => $refund->receipt_number,
            'refunded_at' => self::time($refund->refunded_at),
            'currency' => $refund->currency,
            'total_minor' => (string) $refund->total_minor,
            'tax_minor' => (string) $refund->tax_minor,
            // What the money given back differs from the refund total by (at most a unit per payment).
            'rounding_minor' => (string) $paid->minus((string) $refund->total_minor),
            'base' => [
                'currency' => $refund->base_currency,
                'total_minor' => (string) $refund->base_total_minor,
                'fx' => $refund->fx?->jsonSerialize(),
            ],
            'lines' => $refund->lines->map(fn (RefundLine $line) => self::refundLine($line, $saleLines->get($line->sale_line_id)))->values()->all(),
            'payments' => $refund->payments->map(fn (RefundPayment $payment) => self::payment($payment))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private static function refundLine(RefundLine $line, ?SaleLine $saleLine): array
    {
        $total = (string) $line->total_minor;
        $tax = (string) $line->tax_minor;
        // The refunded share of the sale line's discount, as the line's total share (half up).
        $share = fn (string $amount) => $saleLine === null || BigInteger::of((string) $saleLine->total_minor)->isZero()
            ? '0'
            : (string) BigInteger::of($amount)->multipliedBy($total)->dividedBy((string) $saleLine->total_minor, RoundingMode::HalfUp);
        $discount = $saleLine === null ? self::discount('0', null) : self::discount((string) $saleLine->discount_minor, $saleLine->adjustments);
        $sources = array_map($share, $discount['sources']);
        $discountShare = $share($discount['total_minor']);
        $vouched = array_reduce($sources, fn (BigInteger $sum, string $amount) => $sum->plus($amount), BigInteger::zero());

        return [
            'id' => $line->id,
            'sale_line_id' => $line->sale_line_id,
            'item_id' => $saleLine?->item_id,
            'qty' => self::qty((string) $line->qty),
            'tax_code_id' => $saleLine?->tax_code_id,
            'tax_rate' => $saleLine?->tax_rate === null ? null : (string) $saleLine->tax_rate,
            'tax_inclusive' => (bool) ($saleLine?->tax_inclusive ?? false),
            'net_minor' => (string) BigInteger::of($total)->minus($tax),
            'tax_minor' => $tax,
            'total_minor' => $total,
            'discount' => [
                'total_minor' => $discountShare,
                'sources' => $sources,
                'manual_minor' => (string) BigInteger::max(BigInteger::zero(), BigInteger::of($discountShare)->minus($vouched)),
            ],
        ];
    }

    /**
     * A line's discount by where it came from: each source's amounts from the
     * line's adjustments (positive, capped so they never exceed the
     * discount), and the rest as manual.
     *
     * @return array{total_minor: string, sources: array<string, string>, manual_minor: string}
     */
    private static function discount(string $discount, mixed $adjustments): array
    {
        $left = BigInteger::of($discount);
        $sources = [];

        foreach (is_array($adjustments) ? $adjustments : [] as $adjustment) {
            $amount = is_array($adjustment) ? (string) ($adjustment['amount_minor'] ?? '') : '';
            $source = is_array($adjustment) ? ($adjustment['source'] ?? null) : null;

            if (! is_string($source) || preg_match('/^\d{1,18}$/', $amount) !== 1 || ! $left->isPositive()) {
                continue;
            }

            $take = BigInteger::min(BigInteger::of($amount), $left);
            $sources[$source] = (string) BigInteger::of($sources[$source] ?? '0')->plus($take);
            $left = $left->minus($take);
        }

        return ['total_minor' => $discount, 'sources' => $sources, 'manual_minor' => (string) $left];
    }

    /** @return array<string, mixed> */
    private static function payment(SalePayment|RefundPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'payment_method_id' => $payment->payment_method_id,
            'method_type' => $payment->method_type,
            'currency' => $payment->currency,
            'amount_minor' => (string) $payment->amount_minor,
            'amount_in_sale_minor' => (string) $payment->amount_in_sale_minor,
            'fx' => $payment->fx instanceof FxSnapshot ? $payment->fx->jsonSerialize() : null,
            'tender' => $payment->tender,
        ];
    }

    private static function time(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    /** numeric(18,6) without trailing zeros: "2.000000" → "2". */
    private static function qty(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}
