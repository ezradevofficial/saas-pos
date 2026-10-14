<?php

namespace Modules\Procurement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Documents\LandedCosts;
use Modules\Procurement\Models\LandedCost;
use Modules\Procurement\Models\LandedCostCharge;
use Modules\Procurement\Models\LandedCostReceipt;
use Modules\Procurement\Support\Places;

/**
 * PRC-17: a landed cost; in the detail its charges, GRNs and the allocation
 * table (what posting would allocate for a draft, what it allocated once
 * posted), and what the reader may do.
 *
 * @mixin LandedCost
 */
class LandedCostResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var LandedCost $landedCost */
        $landedCost = $this->resource;
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($request->user(), "procurement.landed_cost.{$permission}", $landedCost->branch_id);

        return [
            'id' => $landedCost->id,
            'number' => $landedCost->number,
            'status' => $landedCost->status,
            'method' => $landedCost->method,
            'description' => $landedCost->description,
            'cost_date' => Refs::date($landedCost->cost_date),
            'company' => Refs::named($landedCost->company),
            'branch' => Refs::named($landedCost->branch),
            'base_total' => $landedCost->base_currency === null ? null : Refs::money($landedCost->base_total_minor, $landedCost->base_currency),
            'created_by' => Refs::user($landedCost->creator),
            'posted_at' => Refs::at($landedCost->posted_at),
            'cancelled_at' => Refs::at($landedCost->cancelled_at),
            'cancel_reason' => $landedCost->cancel_reason,
            'created_at' => Refs::at($landedCost->created_at),
            ...($this->detail ? $this->detailOf($landedCost, $at) : []),
        ];
    }

    /** @return array<string, mixed> */
    private function detailOf(LandedCost $landedCost, \Closure $at): array
    {
        $preview = app(LandedCosts::class)->preview($landedCost);

        return [
            'charges' => $landedCost->charges()->with('party')->get()->map(fn (LandedCostCharge $charge) => [
                'id' => $charge->id, 'description' => $charge->description, 'party' => Refs::named($charge->party),
                'amount' => Refs::money($charge->amount_minor, $charge->currency), 'fx' => Refs::fx($charge->fx),
                'base_amount' => $charge->base_amount_minor === null ? null : Refs::money($charge->base_amount_minor, $landedCost->base_currency),
            ])->all(),
            'receipts' => $landedCost->receiptLinks()->with('receipt')->get()->map(fn (LandedCostReceipt $link) => [
                'id' => $link->receipt->id, 'number' => $link->receipt->number, 'status' => $link->receipt->status,
            ])->all(),
            'allocation' => [
                'base_currency' => $preview['base_currency'],
                'total' => $preview['total_minor'] === null ? null : Refs::money($preview['total_minor'], $preview['base_currency']),
                'error' => $preview['error'] === null ? null : ['code' => $preview['error'], 'message' => __('procurement.errors.'.$preview['error'], ['line' => '', 'pair' => '', 'date' => ''])],
                'lines' => array_map(fn (array $part) => [
                    'receipt_line_id' => $part['receipt_line']->id,
                    'receipt' => ['id' => $part['receipt_line']->receipt?->id, 'number' => $part['receipt_line']->receipt?->number],
                    'item' => Refs::item($part['receipt_line']->item),
                    'accepted_qty' => Refs::qty($part['receipt_line']->accepted_qty),
                    'basis' => Refs::qty($part['basis']),
                    'amount' => Refs::money($part['amount_minor'], $preview['base_currency']),
                ], $preview['lines']),
            ],
            'can' => [
                'edit' => $landedCost->status === LandedCost::DRAFT && $at('create'),
                'post' => $landedCost->status === LandedCost::DRAFT && $at('create'),
                'cancel' => $landedCost->status === LandedCost::DRAFT && $at('cancel'),
            ],
        ];
    }
}
