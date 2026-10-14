<?php

namespace Modules\Procurement\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\CustomFields\OrderEntity;
use Modules\Procurement\Documents\Orders;
use Modules\Procurement\Models\Order;
use Modules\Procurement\Models\OrderLine;
use Modules\Procurement\Support\Places;

/**
 * PRC-13: a purchase order, its supplier, place, currency, rate and totals
 * (in its currency and base currency once submitted, CUR-04); in the
 * detail its lines with what was received, rejected and invoiced, and what
 * the reader may do.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($request->user(), "procurement.po.{$permission}", $order->branch_id);

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'party' => Refs::named($order->party),
            'company' => Refs::named($order->company),
            'branch' => Refs::named($order->branch),
            'location' => Refs::named($order->location),
            'currency' => $order->currency,
            'fx' => Refs::fx($order->fx),
            'tax_inclusive' => (bool) $order->tax_inclusive,
            'order_date' => Refs::date($order->order_date),
            'expected_on' => Refs::date($order->expected_on),
            'terms' => $order->terms,
            'subtotal' => Refs::money($order->subtotal_minor, $order->currency),
            'tax' => Refs::money($order->tax_minor, $order->currency),
            'total' => Refs::money($order->total_minor, $order->currency),
            'base_total' => Refs::money($order->base_total_minor, $order->base_currency),
            'requested_by' => Refs::user($order->requester),
            'requisition_id' => $order->requisition_id,
            'quote_id' => $order->quote_id,
            'bid_id' => $order->bid_id,
            'submitted_at' => Refs::at($order->submitted_at),
            'decided_at' => Refs::at($order->decided_at),
            'sent_at' => Refs::at($order->sent_at),
            'closed_at' => Refs::at($order->closed_at),
            'cancelled_at' => Refs::at($order->cancelled_at),
            'cancel_reason' => $order->cancel_reason,
            'created_at' => Refs::at($order->created_at),
            'custom' => (object) app(CustomFieldPresenter::class)->present($request->user(), OrderEntity::KEY, $order->custom),
            ...($this->detail ? [
                'lines' => $order->lines->map(fn (OrderLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'item' => Refs::item($line->item),
                    'description' => $line->description,
                    'uom' => Refs::uom($line->uom),
                    'qty' => Refs::qty($line->qty),
                    'unit_price' => Refs::money($line->unit_price_minor, $order->currency),
                    'tax_code' => $line->taxCode === null ? null : ['id' => $line->taxCode->id, 'code' => $line->taxCode->code, 'name' => $line->taxCode->name],
                    'tax_rate' => Refs::qty($line->tax_rate),
                    'rate_needed' => Orders::rateNeeded($line),
                    'net' => Refs::money($line->net_minor, $order->currency),
                    'tax' => Refs::money($line->tax_minor, $order->currency),
                    'total' => Refs::money($line->total_minor, $order->currency),
                    'received_qty' => Refs::qty($line->received_qty),
                    'rejected_qty' => Refs::qty($line->rejected_qty),
                    'invoiced_qty' => Refs::qty($line->invoiced_qty),
                    'cost_centre' => Refs::named($line->costCentre),
                    'project' => Refs::named($line->project),
                    'requisition_line_id' => $line->requisition_line_id,
                ])->all(),
                'can' => [
                    'edit' => $order->status === Order::DRAFT && $at('create'),
                    'submit' => $order->status === Order::DRAFT && $at('create'),
                    'cancel' => in_array($order->status, [Order::DRAFT, Order::PENDING, Order::APPROVED], true) && $at('cancel'),
                    'send' => in_array($order->status, [Order::APPROVED, Order::PARTIALLY_RECEIVED, Order::RECEIVED], true) && $at('create'),
                    'print' => $order->status !== Order::CANCELLED && $at('print'),
                    'receive' => in_array($order->status, Order::RECEIVABLE, true),
                    'invoice' => in_array($order->status, Order::INVOICEABLE, true),
                ],
            ] : []),
        ];
    }
}
