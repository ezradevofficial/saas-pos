<?php

namespace Modules\Inventory\Http\Resources;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\Adjustment;
use Modules\Inventory\Models\AdjustmentLine;
use Modules\Inventory\Stock\Qty;

/**
 * INV-10, INV-19: an adjustment, its reason and status (a failed one says
 * why), its values in and out (valuation holders only), and in the detail
 * its lines and whether the reader may cancel it.
 *
 * @mixin Adjustment
 */
class AdjustmentResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Adjustment $adjustment */
        $adjustment = $this->resource;
        $values = Values::visible($request, $adjustment->location_id);

        return [
            'id' => $adjustment->id,
            'number' => $adjustment->number,
            'status' => $adjustment->status,
            'reason' => $adjustment->reason,
            'note' => $adjustment->note,
            'failure' => $adjustment->failure,
            'company' => Refs::place($adjustment->company),
            'branch' => Refs::place($adjustment->branch),
            'location' => Refs::place($adjustment->location),
            'requested_by' => Refs::user($adjustment->requester),
            'value_in' => $values ? Values::money($adjustment->value_in_minor, $adjustment->currency) : null,
            'value_out' => $values ? Values::money($adjustment->value_out_minor, $adjustment->currency) : null,
            'lines_count' => $adjustment->lines_count ?? $adjustment->lines()->count(),
            'decided_at' => Refs::at($adjustment->decided_at),
            'posted_at' => Refs::at($adjustment->posted_at),
            'cancelled_at' => Refs::at($adjustment->cancelled_at),
            'cancel_reason' => $adjustment->cancel_reason,
            'created_at' => Refs::at($adjustment->created_at),
            ...($this->detail ? [
                'lines' => $adjustment->lines->map(fn (AdjustmentLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'item' => Refs::item($line->item),
                    'uom' => Refs::uom($line->uom),
                    'qty' => Qty::show($line->qty),
                    'qty_base' => Qty::show($line->qty_base),
                    'batch' => Refs::batch($line->batch),
                    'batch_number' => $line->batch_number,
                    'expires_on' => $line->expires_on?->toDateString(),
                    'serials' => $line->serials ?? [],
                    'unit_cost' => $values && $line->unit_cost_minor !== null ? Values::money($line->unit_cost_minor, $line->unit_cost_currency) : null,
                    'value' => $values ? Values::money($line->value_minor, $adjustment->currency) : null,
                ])->all(),
                'can' => [
                    'cancel' => $adjustment->status === Adjustment::PENDING
                        && app(ScopeResolver::class)->can($request->user(), 'inventory.adjustment.cancel', Scope::location($adjustment->location_id)),
                ],
            ] : []),
        ];
    }
}
