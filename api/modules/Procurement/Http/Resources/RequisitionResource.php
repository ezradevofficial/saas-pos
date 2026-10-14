<?php

namespace Modules\Procurement\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\CustomFields\RequisitionEntity;
use Modules\Procurement\Models\Requisition;
use Modules\Procurement\Models\RequisitionLine;
use Modules\Procurement\Support\Places;

/**
 * PRC-02: a requisition, its place, requester, status and estimated total;
 * in the detail its lines and what the reader may do with it.
 *
 * @mixin Requisition
 */
class RequisitionResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Requisition $requisition */
        $requisition = $this->resource;
        $places = app(Places::class);
        $at = fn (string $permission) => $places->at($request->user(), "procurement.requisition.{$permission}", $requisition->branch_id);

        return [
            'id' => $requisition->id,
            'number' => $requisition->number,
            'status' => $requisition->status,
            'purpose' => $requisition->purpose,
            'needed_by' => Refs::date($requisition->needed_by),
            'company' => Refs::named($requisition->company),
            'branch' => Refs::named($requisition->branch),
            'location' => Refs::named($requisition->location),
            'department' => Refs::named($requisition->department),
            'requested_by' => Refs::user($requisition->requester),
            'estimated_total' => Refs::money($requisition->estimated_total_minor, $requisition->currency),
            'currency' => $requisition->currency,
            'submitted_at' => Refs::at($requisition->submitted_at),
            'decided_at' => Refs::at($requisition->decided_at),
            'cancelled_at' => Refs::at($requisition->cancelled_at),
            'cancel_reason' => $requisition->cancel_reason,
            'created_at' => Refs::at($requisition->created_at),
            'custom' => (object) app(CustomFieldPresenter::class)->present($request->user(), RequisitionEntity::KEY, $requisition->custom),
            ...($this->detail ? [
                'lines' => $requisition->lines->map(fn (RequisitionLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'item' => Refs::item($line->item),
                    'description' => $line->description,
                    'uom' => Refs::uom($line->uom),
                    'qty' => Refs::qty($line->qty),
                    'estimated_unit_price' => Refs::money($line->estimated_unit_price_minor, $requisition->currency),
                    'cost_centre' => Refs::named($line->costCentre),
                    'project' => Refs::named($line->project),
                    'needed_by' => Refs::date($line->needed_by),
                ])->all(),
                'can' => [
                    'edit' => $requisition->status === Requisition::DRAFT && $at('create'),
                    'submit' => $requisition->status === Requisition::DRAFT && $at('create'),
                    'cancel' => in_array($requisition->status, [Requisition::DRAFT, Requisition::PENDING], true) && $at('cancel'),
                    'order' => $requisition->status === Requisition::APPROVED,
                ],
            ] : []),
        ];
    }
}
