<?php

namespace Modules\Stores\Http\Resources;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Models\RequisitionLine;
use Modules\Stores\Stock\Qty;

/**
 * STR-02 to STR-04: a requisition, its store, department and status, and
 * in the detail its lines (asked, issued, still open), its issues and what
 * the reader may do with it.
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

        return [
            'id' => $requisition->id,
            'number' => $requisition->number,
            'status' => $requisition->status,
            'company' => Refs::named($requisition->company),
            'branch' => Refs::named($requisition->branch),
            'location' => Refs::named($requisition->location),
            'department' => Refs::dimension($requisition->department),
            'cost_centre' => Refs::dimension($requisition->costCentre),
            'needed_by' => $requisition->needed_by?->toDateString(),
            'note' => $requisition->note,
            'requested_by' => Refs::user($requisition->requester),
            'lines_count' => $requisition->lines_count ?? $requisition->lines()->count(),
            'decided_at' => Refs::at($requisition->decided_at),
            'closed_at' => Refs::at($requisition->closed_at),
            'close_reason' => $requisition->close_reason,
            'cancelled_at' => Refs::at($requisition->cancelled_at),
            'cancel_reason' => $requisition->cancel_reason,
            'created_at' => Refs::at($requisition->created_at),
            ...($this->detail ? $this->details($requisition, $request) : []),
        ];
    }

    private function details(Requisition $requisition, Request $request): array
    {
        $resolver = app(ScopeResolver::class);
        $at = Scope::location($requisition->location_id);
        $issuable = in_array($requisition->status, Requisition::ISSUABLE, true);
        $untouched = $requisition->status === Requisition::PENDING
            || ($requisition->status === Requisition::APPROVED && $requisition->lines->every(fn (RequisitionLine $line) => BigDecimal::of((string) $line->qty_issued)->isZero()));

        return [
            'lines' => $requisition->lines->map(fn (RequisitionLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'item' => Refs::item($line->item),
                'uom' => Refs::uom($line->uom),
                'qty' => Qty::show($line->qty),
                'qty_base' => Qty::show($line->qty_base),
                'qty_issued' => Qty::show($line->qty_issued),
                'qty_open' => Qty::show(BigDecimal::of((string) $line->qty)->minus((string) $line->qty_issued)),
                'note' => $line->note,
            ])->all(),
            'issues' => $requisition->issues->map(fn (Issue $issue) => [
                'id' => $issue->id,
                'number' => $issue->number,
                'status' => $issue->status,
                'issued_at' => Refs::at($issue->issued_at),
            ])->all(),
            'can' => [
                'cancel' => $untouched && $resolver->can($request->user(), 'stores.requisition.cancel', $at),
                'issue' => $issuable && $resolver->can($request->user(), 'stores.issue.create', $at),
                'close' => $issuable && $resolver->can($request->user(), 'stores.issue.create', $at),
            ],
        ];
    }
}
