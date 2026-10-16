<?php

namespace Modules\Manufacturing\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Manufacturing\Boms\Versions;
use Modules\Manufacturing\CustomFields\BomEntity;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Support\Places;
use Modules\Manufacturing\Support\Qty;

/**
 * MFG-02, MFG-03: a BOM: its company, output item, kind, name, the version
 * in force now and whether a draft exists; in the detail every version
 * (number, status, date, output) and what the reader may do.
 *
 * @mixin Bom
 */
class BomResource extends JsonResource
{
    private bool $detail = false;

    public function detail(): self
    {
        $this->detail = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        /** @var Bom $bom */
        $bom = $this->resource;
        // Review item 4: a list page loads these once (Bom::versionLoads); a single BOM reads them here.
        $current = $bom->relationLoaded('publishedVersions') ? $bom->publishedVersions->first() : app(Versions::class)->ofBom($bom->id, CarbonImmutable::now());
        $draft = $bom->relationLoaded('draftVersion')
            ? $bom->draftVersion?->id
            : BomVersion::query()->where('bom_id', $bom->id)->where('status', BomVersion::DRAFT)->value('id');

        return [
            'id' => $bom->id,
            'kind' => $bom->kind,
            'name' => $bom->name,
            'company' => Refs::named($bom->company),
            'item' => Refs::item($bom->item),
            'archived_at' => Refs::at($bom->archived_at),
            'current_version' => $current === null ? null : self::version($current),
            'draft_id' => $draft,
            'custom' => (object) app(CustomFieldPresenter::class)->present($request->user(), BomEntity::KEY, $bom->custom),
            ...($this->detail ? $this->details($bom, $request) : []),
        ];
    }

    /** @return array<string, mixed> */
    public static function version(BomVersion $version): array
    {
        return [
            'id' => $version->id,
            'version_no' => $version->version_no,
            'status' => $version->status,
            'effective_from' => Refs::at($version->effective_from),
            'output_qty' => Qty::show($version->output_qty),
            'output_uom_id' => $version->output_uom_id,
            'labour_minutes' => $version->labour_minutes,
        ];
    }

    private function details(Bom $bom, Request $request): array
    {
        $manage = $bom->archived_at === null && app(Places::class)->atCompany($request->user(), 'manufacturing.bom.manage', $bom->company_id);

        return [
            'versions' => $bom->versions->map(fn (BomVersion $version) => self::version($version))->all(),
            'can' => ['manage' => $manage, 'costs' => Costs::inCompany($request, $bom->company_id)],
        ];
    }
}
