<?php

namespace Modules\FixedAssets\Http\Resources;

use App\Core\CustomFields\CustomFieldPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\CustomFields\AssetEntity;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Support\Access;

/**
 * FA-04, FA-26: an asset as the register shows it. Costs and book values
 * are null without `fixed_assets.value.view` at its location (RBAC-05);
 * `can` says which actions the reader may take on it there.
 *
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Asset $asset */
        $asset = $this->resource;
        $values = Values::at($request, $asset->location_id);
        $base = $asset->base_currency ?? $asset->currency;
        $money = fn ($minor, ?string $currency = null) => Values::money($values, $minor, $currency ?? $base);
        $active = $asset->status === Asset::ACTIVE && $asset->archived_at === null;
        $draft = $asset->status === Asset::DRAFT && $asset->archived_at === null;
        $user = $request->user();
        $access = app(Access::class);
        $may = fn (string $permission) => $user !== null && $access->at($user, $permission, $asset);

        return [
            'id' => $asset->id,
            'number' => $asset->number,
            'name' => $asset->name,
            'description' => $asset->description,
            'status' => $asset->status,
            'archived_at' => $asset->archived_at?->toIso8601String(),
            'source' => $asset->source,
            'opening' => (bool) $asset->opening,
            'company' => Refs::of($asset->company),
            'branch' => Refs::of($asset->branch),
            'location' => Refs::of($asset->location),
            'category' => Refs::category($asset->category),
            'custodian' => Refs::custodian($request, $asset->custodianUser, $asset->custodian_employee_id),
            'cost_centre' => Refs::of($asset->costCentre),
            'department' => Refs::of($asset->department),
            'serial_number' => $asset->serial_number,
            'acquired_on' => Refs::date($asset->acquired_on),
            'in_service_on' => Refs::date($asset->in_service_on),
            'cost' => $money($asset->cost_minor, $asset->currency),
            'fx' => $values ? $asset->fx?->jsonSerialize() : null,
            'base_cost' => $money($asset->base_cost_minor),
            'supplier' => Refs::of($asset->supplier),
            'supplier_reference' => $asset->supplier_reference,
            'warranty_until' => Refs::date($asset->warranty_until),
            'warranty_note' => $asset->warranty_note,
            'method' => $asset->method,
            'useful_life_months' => $asset->useful_life_months,
            'residual' => $money($asset->residual_minor),
            'declining_rate' => $asset->declining_rate === null ? null : (string) $asset->declining_rate,
            'total_units' => $asset->total_units === null ? null : (string) $asset->total_units,
            'meter_start' => (string) $asset->meter_start,
            'book_cost' => $money($asset->book_cost_minor),
            'accumulated' => $money($asset->accumulated_minor),
            'impairment' => $money($asset->impairment_minor),
            'surplus' => $money($asset->surplus_minor),
            'book_value' => $money($asset->bookValue()),
            'depreciated_to' => Refs::date($asset->depreciated_to),
            'disposed_on' => Refs::date($asset->disposed_on),
            'activated_at' => $asset->activated_at?->toIso8601String(),
            'custom' => (object) ($user === null ? [] : app(CustomFieldPresenter::class)->present($user, AssetEntity::KEY, $asset->custom)),
            'created_at' => $asset->created_at?->toIso8601String(),
            'can' => [
                'edit' => ($draft || $active) && $may('fixed_assets.asset.manage'),
                'activate' => $draft && $may('fixed_assets.asset.manage'),
                'cancel' => $draft && $may('fixed_assets.asset.manage'),
                'estimates' => $active && $may('fixed_assets.asset.manage'),
                'transfer' => $active && $may('fixed_assets.transfer.request'),
                'dispose' => $active && $may('fixed_assets.disposal.request'),
                'revalue' => $active && $may('fixed_assets.revaluation.post'),
                'maintain' => ($draft || $active) && $may('fixed_assets.maintenance.manage'),
                'print' => $may('fixed_assets.label.print'),
            ],
        ];
    }
}
