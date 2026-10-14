<?php

namespace Modules\Stores\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Stores\Models\ReturnLine;
use Modules\Stores\Models\StoreReturn;
use Modules\Stores\Stock\Qty;

/**
 * STR-06, STR-09: a return to store, its issue, department, reason and
 * value (holders of `stores.value.view` at the store, once valued), and in
 * the detail its lines.
 *
 * @mixin StoreReturn
 */
class ReturnResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var StoreReturn $return */
        $return = $this->resource;
        $values = Values::visible($request, $return->location_id);

        return [
            'id' => $return->id,
            'number' => $return->number,
            'ledger' => $return->ledger,
            'reason' => $return->reason,
            'note' => $return->note,
            'issue' => $return->issue === null ? null : ['id' => $return->issue->id, 'number' => $return->issue->number],
            'company' => Refs::named($return->company),
            'branch' => Refs::named($return->branch),
            'location' => Refs::named($return->location),
            'department' => Refs::dimension($return->department),
            'cost_centre' => Refs::dimension($return->costCentre),
            'returned_by' => Refs::user($return->returner),
            'returned_at' => Refs::at($return->returned_at),
            'valued' => $return->valued_at !== null,
            'value' => $values && $return->valued_at !== null ? Values::money((string) $return->lines()->sum('value_minor'), $return->currency) : null,
            'lines_count' => $return->lines_count ?? $return->lines()->count(),
            ...($this->detail ? [
                'lines' => $return->lines->map(fn (ReturnLine $line) => [
                    'id' => $line->id,
                    'line_no' => $line->line_no,
                    'issue_line_id' => $line->issue_line_id,
                    'item' => Refs::item($line->item),
                    'uom' => Refs::uom($line->uom),
                    'qty' => Qty::show($line->qty),
                    'qty_base' => Qty::show($line->qty_base),
                    'value' => $values ? Values::money($line->value_minor, $return->currency) : null,
                ])->all(),
            ] : []),
        ];
    }
}
