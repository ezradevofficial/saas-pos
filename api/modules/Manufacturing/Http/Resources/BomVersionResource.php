<?php

namespace Modules\Manufacturing\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomLine;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Places;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-03, MFG-04: a BOM version with its lines (item, unit, quantity,
 * yield, wastage, explode, cost share), its BOM and what the reader may do
 * (edit and publish a draft).
 *
 * @mixin BomVersion
 */
class BomVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BomVersion $version */
        $version = $this->resource;
        /** @var Bom $bom */
        $bom = $version->bom;
        $manage = $version->isDraft() && $bom->archived_at === null && app(Places::class)->atCompany($request->user(), 'manufacturing.bom.manage', $bom->company_id);

        return [
            ...BomResource::version($version),
            'bom' => ['id' => $bom->id, 'kind' => $bom->kind, 'name' => $bom->name, 'company' => Refs::named($bom->company), 'item' => Refs::item($bom->item)],
            'output_uom' => Refs::uom($version->outputUom),
            'note' => $version->note,
            'published_by' => Refs::user($version->publisher),
            'published_at' => Refs::at($version->published_at),
            'lines' => $version->lines->map(fn (BomLine $line) => [
                'id' => $line->id,
                'line_no' => $line->line_no,
                'kind' => $line->kind,
                'item' => Refs::item($line->item),
                'uom' => Refs::uom($line->uom),
                'qty' => Qty::show($line->qty),
                'qty_base' => Qty::show($line->qty_base),
                'yield_percent' => Qty::show($line->yield_percent),
                'wastage_percent' => Qty::show($line->wastage_percent),
                'explode' => $line->explode,
                'cost_share_percent' => Qty::show($line->cost_share_percent),
                'note' => $line->note,
            ])->all(),
            'can' => ['edit' => $manage, 'publish' => $manage],
        ];
    }
}
