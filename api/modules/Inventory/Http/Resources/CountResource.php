<?php

namespace Modules\Inventory\Http\Resources;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Inventory\Models\CountLine;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Stock\Qty;

/**
 * INV-09, INV-19: a stock count, its status and times, its gain and loss
 * (valuation holders only), and in the detail its lines (expected at the
 * freeze, counted, variance) and what the reader may do next.
 *
 * @mixin StockCount
 */
class CountResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var StockCount $count */
        $count = $this->resource;
        $values = Values::visible($request, $count->location_id);
        $resolver = app(ScopeResolver::class);
        $here = Scope::location($count->location_id);

        return [
            'id' => $count->id,
            'number' => $count->number,
            'kind' => $count->kind,
            'status' => $count->status,
            'note' => $count->note,
            'company' => Refs::place($count->company),
            'branch' => Refs::place($count->branch),
            'location' => Refs::place($count->location),
            'requested_by' => Refs::user($count->requester),
            'gain' => $values ? Values::money($count->gain_minor, $count->currency) : null,
            'loss' => $values ? Values::money($count->loss_minor, $count->currency) : null,
            'lines_count' => $count->lines_count ?? $count->lines()->count(),
            'frozen_at' => Refs::at($count->frozen_at),
            'submitted_at' => Refs::at($count->submitted_at),
            'decided_at' => Refs::at($count->decided_at),
            'posted_at' => Refs::at($count->posted_at),
            'cancelled_at' => Refs::at($count->cancelled_at),
            'cancel_reason' => $count->cancel_reason,
            'created_at' => Refs::at($count->created_at),
            ...($this->detail ? [
                'lines' => $count->lines->sortBy(fn (CountLine $line) => [$line->item?->code, $line->batch?->batch_number])->values()->map(fn (CountLine $line) => [
                    'id' => $line->id,
                    'item' => Refs::item($line->item),
                    'base_uom' => Refs::uom($line->item?->baseUom),
                    'batch' => Refs::batch($line->batch),
                    'expected_qty' => Qty::show($line->expected_qty),
                    'counted_qty' => Qty::show($line->counted_qty),
                    'variance_qty' => Qty::show($line->variance_qty),
                    'variance_value' => $values ? Values::money($line->variance_value_minor, $count->currency) : null,
                ])->all(),
                'can' => [
                    'count' => $count->status === StockCount::COUNTING && $resolver->can($request->user(), 'inventory.count.count', $here),
                    'cancel' => in_array($count->status, [StockCount::COUNTING, StockCount::PENDING], true) && $resolver->can($request->user(), 'inventory.count.cancel', $here),
                ],
            ] : []),
        ];
    }
}
