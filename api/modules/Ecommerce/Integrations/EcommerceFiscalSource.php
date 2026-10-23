<?php

namespace Modules\Ecommerce\Integrations;

use App\Core\Fiscal\Contracts\FiscalDocumentSource;
use App\Core\Fiscal\FiscalDocument;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\OrderLine;
use Modules\Ecommerce\Models\OrderReturn;
use Modules\Ecommerce\Models\ReturnLine;
use Modules\Ecommerce\Models\Store;

/**
 * ECOM-35: online orders for the core fiscal queue (FiscalDocumentSource,
 * key `ecommerce`), built from the module's own tables in the document's
 * tenant. A delivered order is an invoice (`sale`, its id); a return of a
 * delivered order is a credit note (`refund`, the same id) naming it. The
 * delivery fee is a line of its own. Lines carry the tax code and rate the
 * order was priced with; nothing is recomputed.
 *
 * ECOM-42, ECOM-43: a partial return is a credit note (`refund`, the
 * return's id) of the lines that came back, naming the order; an
 * exchange's item is an invoice of its own (`sale`, the exchange's sale
 * id). Neither carries the delivery fee.
 */
final class EcommerceFiscalSource implements FiscalDocumentSource
{
    public const KEY = 'ecommerce';

    public function key(): string
    {
        return self::KEY;
    }

    public function document(string $documentType, string $documentId): FiscalDocument
    {
        $order = Order::query()->find($documentId);

        if ($order === null) {
            return $this->returnDocument($documentType, $documentId);
        }

        $refund = $documentType === 'refund';
        $withFee = ! $refund || $order->return_fee;
        $lines = OrderLine::query()->where('order_id', $order->id)->orderBy('line_no')->get();
        $codes = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('code', 'id');
        $rows = $lines->map(fn (OrderLine $line) => [
            'line_no' => $line->line_no,
            'item_id' => $line->item_id,
            'item_code' => $codes[$line->item_id] ?? null,
            'item_name' => $line->name,
            'qty' => (string) $line->qty,
            'unit_price_minor' => $line->unit_price_minor,
            'discount_minor' => $refund ? 0 : $line->discount_minor,
            'tax_code_id' => $line->tax_code_id,
            'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
            'tax_minor' => $line->tax_minor,
            'total_minor' => $line->total_minor,
        ])->values()->all();
        $tax = (int) $lines->sum('tax_minor');
        $total = (int) $lines->sum('total_minor');
        // The fee with its tax (inclusive or not): what the order totals above its lines.
        $fee = $order->total_minor - $total;

        if ($withFee && $fee > 0) {
            $rows[] = [
                'line_no' => count($rows) + 1,
                'item_name' => __('ecommerce.fiscal.delivery', [], $order->locale),
                'qty' => '1',
                'unit_price_minor' => $fee,
                'discount_minor' => 0,
                'tax_code_id' => Store::query()->whereKey($order->store_id)->value('delivery_tax_code_id'),
                'tax_minor' => $order->delivery_tax_minor,
                'total_minor' => $fee,
            ];
            $tax += $order->delivery_tax_minor;
            $total += $fee;
        }

        $party = $order->party_id === null ? null : Party::query()->find($order->party_id);

        return FiscalDocument::fromArray(array_filter([
            'type' => $refund ? 'refund' : 'sale',
            'id' => $order->id,
            'number' => $order->number,
            'issued_at' => ($refund ? $order->returned_at : $order->delivered_at)?->toIso8601String() ?? now()->toIso8601String(),
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'location_id' => $order->location_id,
            'currency' => $order->currency,
            'customer' => $party === null ? null : ['tin' => $party->tax_id, 'name' => $party->name],
            'payment_type' => match ($order->payment_kind) {
                'mpesa' => 'mobile_money',
                'card' => 'card',
                'cod' => 'cash',
                default => 'other',
            },
            'original' => $refund ? ['type' => 'sale', 'id' => $order->id] : null,
            'lines' => $rows,
            'totals' => ['tax_minor' => $tax, 'total_minor' => $total],
        ], fn ($value) => $value !== null));
    }

    private function returnDocument(string $documentType, string $documentId): FiscalDocument
    {
        $refund = $documentType === 'refund';
        $return = $refund ? OrderReturn::query()->findOrFail($documentId) : OrderReturn::query()->where('exchange_sale_id', $documentId)->firstOrFail();
        $order = Order::query()->findOrFail($return->order_id);
        $lines = ReturnLine::query()->where('return_id', $return->id)->where('direction', $refund ? ReturnLine::IN : ReturnLine::OUT)->orderBy('line_no')->get();
        $codes = Item::query()->whereKey($lines->pluck('item_id')->unique()->all())->pluck('code', 'id');
        $party = $order->party_id === null ? null : Party::query()->find($order->party_id);

        return FiscalDocument::fromArray(array_filter([
            'type' => $refund ? 'refund' : 'sale',
            'id' => $documentId,
            'number' => $return->number,
            'issued_at' => ($refund ? $return->at : ($return->exchange_posted_at ?? $return->at))->toIso8601String(),
            'company_id' => $order->company_id,
            'branch_id' => $order->branch_id,
            'location_id' => $order->location_id,
            'currency' => $order->currency,
            'customer' => $party === null ? null : ['tin' => $party->tax_id, 'name' => $party->name],
            'payment_type' => match ($order->payment_kind) {
                'mpesa' => 'mobile_money',
                'card' => 'card',
                'cod' => 'cash',
                default => 'other',
            },
            'original' => $refund ? ['type' => 'sale', 'id' => $order->id] : null,
            'lines' => $lines->values()->map(fn (ReturnLine $line, int $i) => [
                'line_no' => $i + 1,
                'item_id' => $line->item_id,
                'item_code' => $codes[$line->item_id] ?? null,
                'item_name' => $line->name,
                'qty' => (string) $line->qty,
                'unit_price_minor' => $line->unit_price_minor,
                'discount_minor' => $refund ? 0 : $line->discount_minor,
                'tax_code_id' => $line->tax_code_id,
                'tax_rate' => $line->tax_rate === null ? null : (string) $line->tax_rate,
                'tax_minor' => $line->tax_minor,
                'total_minor' => $line->total_minor,
            ])->all(),
            'totals' => ['tax_minor' => (int) $lines->sum('tax_minor'), 'total_minor' => (int) $lines->sum('total_minor')],
        ], fn ($value) => $value !== null));
    }
}
