<?php

namespace Modules\Stores\Http\Resources;

use App\Core\Rbac\Scope;
use App\Core\Rbac\ScopeResolver;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\IssueLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Qty;

/**
 * STR-04 to STR-06, STR-09: an issue, its requisition, department, ledger
 * and status, its value (holders of `stores.value.view` at the store, once
 * valued), and in the detail its lines (issued, returned, value), its
 * returns and what the reader may do with it.
 *
 * @mixin Issue
 */
class IssueResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Issue $issue */
        $issue = $this->resource;
        $values = Values::visible($request, $issue->location_id);

        return [
            'id' => $issue->id,
            'number' => $issue->number,
            'status' => $issue->status,
            'ledger' => $issue->ledger,
            'requisition' => $issue->requisition === null ? null : ['id' => $issue->requisition->id, 'number' => $issue->requisition->number],
            'company' => Refs::named($issue->company),
            'branch' => Refs::named($issue->branch),
            'location' => Refs::named($issue->location),
            'department' => Refs::dimension($issue->department),
            'cost_centre' => Refs::dimension($issue->costCentre),
            'note' => $issue->note,
            'issued_by' => Refs::user($issue->issuer),
            'issued_at' => Refs::at($issue->issued_at),
            'acknowledged_by' => Refs::user($issue->acknowledger),
            'acknowledged_at' => Refs::at($issue->acknowledged_at),
            'acknowledge_note' => $issue->acknowledge_note,
            'valued' => $issue->valued_at !== null,
            'value' => $values && $issue->valued_at !== null ? Values::money((string) (array_key_exists('lines_sum_value_minor', $issue->getAttributes())
                ? ($issue->lines_sum_value_minor ?? 0) : $issue->lines()->sum('value_minor')), $issue->currency) : null,
            'lines_count' => $issue->lines_count ?? $issue->lines()->count(),
            ...($this->detail ? $this->details($issue, $request, $values) : []),
        ];
    }

    private function details(Issue $issue, Request $request, bool $values): array
    {
        $resolver = app(ScopeResolver::class);
        $user = $request->user();
        $at = Scope::location($issue->location_id);
        $returnable = $issue->lines->contains(fn (IssueLine $line) => BigDecimal::of((string) $line->qty_returned_base)->isLessThan((string) $line->qty_base));

        return [
            'lines' => $issue->lines->map(fn (IssueLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'requisition_line_id' => $line->requisition_line_id,
                'item' => Refs::item($line->item),
                'uom' => Refs::uom($line->uom),
                'qty' => Qty::show($line->qty),
                'qty_base' => Qty::show($line->qty_base),
                'qty_returned_base' => Qty::show($line->qty_returned_base),
                'value' => $values ? Values::money($line->value_minor, $issue->currency) : null,
            ])->all(),
            'returns' => $issue->returns->map(fn (StoreReturn $return) => [
                'id' => $return->id,
                'number' => $return->number,
                'reason' => $return->reason,
                'returned_at' => Refs::at($return->returned_at),
            ])->all(),
            'can' => [
                'acknowledge' => $issue->status === Issue::ISSUED && $issue->issued_by !== $user->id
                    && $resolver->can($user, 'stores.issue.acknowledge', $at),
                'return' => $returnable && $resolver->can($user, 'stores.return.create', $at),
            ],
        ];
    }
}
