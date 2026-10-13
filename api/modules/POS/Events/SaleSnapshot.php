<?php

namespace Modules\POS\Events;

use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\RefundPayment;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SalePayment;

/**
 * POS-13, LOY-27: what a stored sale or refund was, as plain data for the
 * modules listening to SaleCompleted, SaleVoided and SaleRefunded
 * (Loyalty, later Inventory and Accounting), so they never read POS
 * tables (CLAUDE.md, modular monolith). Amounts are minor-unit digit
 * strings, quantities decimal strings, times ISO 8601 in UTC; other
 * modules' data (`extensions`, `adjustments`, `tender`) is passed on as
 * the till sent it.
 */
final class SaleSnapshot
{
    /** @return array<string, mixed> */
    public static function sale(Sale $sale): array
    {
        $sale->loadMissing(['lines', 'payments']);

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
            'sold_at' => $sale->sold_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'offline' => (bool) $sale->offline,
            'currency' => $sale->currency,
            'totals' => [
                'subtotal_minor' => (string) $sale->subtotal_minor,
                'discount_minor' => (string) $sale->discount_minor,
                'tax_minor' => (string) $sale->tax_minor,
                'total_minor' => (string) $sale->total_minor,
            ],
            // CUR-04: the base currency, the total in it and the rate used.
            'base' => [
                'currency' => $sale->base_currency,
                'total_minor' => (string) $sale->base_total_minor,
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
                'tax_minor' => (string) $line->tax_minor,
                'total_minor' => (string) $line->total_minor,
                'adjustments' => $line->adjustments,
            ])->values()->all(),
            'payments' => $sale->payments->map(fn (SalePayment $payment) => self::payment($payment))->values()->all(),
            'extensions' => $sale->extensions,
        ];
    }

    /** @return array<string, mixed> */
    public static function refund(Refund $refund): array
    {
        $refund->loadMissing(['lines', 'payments']);

        return [
            'id' => $refund->id,
            'sale_id' => $refund->sale_id,
            'company_id' => $refund->company_id,
            'branch_id' => $refund->branch_id,
            'receipt_number' => $refund->receipt_number,
            'refunded_at' => $refund->refunded_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'currency' => $refund->currency,
            'total_minor' => (string) $refund->total_minor,
            'lines' => $refund->lines->map(fn (RefundLine $line) => [
                'sale_line_id' => $line->sale_line_id,
                'qty' => self::qty((string) $line->qty),
                'total_minor' => (string) $line->total_minor,
            ])->values()->all(),
            'payments' => $refund->payments->map(fn (RefundPayment $payment) => self::payment($payment))->values()->all(),
        ];
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
            'tender' => $payment->tender,
        ];
    }

    /** numeric(18,6) without trailing zeros: "2.000000" → "2". */
    private static function qty(string $qty): string
    {
        return str_contains($qty, '.') ? rtrim(rtrim($qty, '0'), '.') : $qty;
    }
}
