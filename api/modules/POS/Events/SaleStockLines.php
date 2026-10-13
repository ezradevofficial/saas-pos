<?php

namespace Modules\POS\Events;

use Modules\POS\Models\Refund;
use Modules\POS\Models\RefundLine;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleLine;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Sync\Records;

/**
 * POS-13: what a sale, void or refund did to stock, as the POS module
 * tells it to the modules that listen to its events (Inventory, docs/
 * modules/inventory.md INV-14), so they never read the POS tables
 * (architecture rule 1). Read in the event's tenant; quantities are
 * decimal strings in the line's unit, as sold.
 *
 * @phpstan-type StockLine array{line_id: string, sale_line_id: string, item_id: string, uom_id: string, qty: string}
 * @phpstan-type StockLines array{sale_id: string, company_id: string, location_id: string, occurred_at: string, lines: list<StockLine>}
 */
final class SaleStockLines
{
    /**
     * A sale's lines (null when it is not stored), with its status
     * (`completed` or `voided`), each line's `refunded_qty` and the ids of
     * its applied refunds, so a listener that sees the sale after its void
     * or refunds can account for them.
     *
     * @return (StockLines&array{status: string, refund_ids: list<string>})|null
     */
    public static function forSale(string $saleId): ?array
    {
        $sale = Sale::query()->find($saleId);

        if ($sale === null) {
            return null;
        }

        return [
            ...self::of($sale, $sale->sold_at->toIso8601String(), SaleLine::query()->where('sale_id', $sale->id)->orderBy('line_no')->get()
                ->map(fn (SaleLine $line) => [...self::line($line->id, $line), 'refunded_qty' => (string) $line->refunded_qty])->all()),
            'status' => $sale->status,
            'refund_ids' => Refund::query()->where('sale_id', $sale->id)->where('status', Records::APPLIED)->orderBy('refunded_at')->orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ];
    }

    /** @return StockLines|null the lines of the sale a void cancelled */
    public static function forVoid(string $voidId): ?array
    {
        $void = SaleVoid::query()->find($voidId);
        $sale = $void === null ? null : Sale::query()->find($void->sale_id);

        return $sale === null ? null : self::of($sale, $void->voided_at->toIso8601String(), SaleLine::query()->where('sale_id', $sale->id)->orderBy('line_no')->get()
            ->map(fn (SaleLine $line) => self::line($line->id, $line))->all());
    }

    /** @return StockLines|null a refund's lines (each naming the sale line it gives back) */
    public static function forRefund(string $refundId): ?array
    {
        $refund = Refund::query()->find($refundId);
        $sale = $refund === null ? null : Sale::query()->find($refund->sale_id);

        if ($sale === null) {
            return null;
        }

        $saleLines = SaleLine::query()->where('sale_id', $sale->id)->get()->keyBy('id');

        return self::of($sale, $refund->refunded_at->toIso8601String(), RefundLine::query()->where('refund_id', $refund->id)->orderBy('id')->get()
            ->filter(fn (RefundLine $line) => $saleLines->has($line->sale_line_id))
            ->map(fn (RefundLine $line) => [...self::line($line->id, $saleLines[$line->sale_line_id]), 'qty' => (string) $line->qty])
            ->values()->all());
    }

    private static function of(Sale $sale, string $at, array $lines): array
    {
        return ['sale_id' => $sale->id, 'company_id' => $sale->company_id, 'location_id' => $sale->location_id, 'occurred_at' => $at, 'lines' => $lines];
    }

    private static function line(string $id, SaleLine $line): array
    {
        return ['line_id' => $id, 'sale_line_id' => $line->id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'qty' => (string) $line->qty];
    }
}
