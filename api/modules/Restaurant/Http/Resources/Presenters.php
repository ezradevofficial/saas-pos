<?php

namespace Modules\Restaurant\Http\Resources;

use Modules\Restaurant\Billing\Charges;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\Table;

/** The shapes of docs/modules/restaurant.md (Response shapes) for set-up records. */
final class Presenters
{
    /** @return array<string, mixed> */
    public static function area(Area $area): array
    {
        return ['id' => $area->id, 'location_id' => $area->location_id, 'name' => $area->name, 'sort' => (int) $area->sort, 'archived_at' => $area->archived_at?->toIso8601String()];
    }

    /**
     * @param  array<string, mixed>|null  $order  the open order's summary, when the table is in use
     * @return array<string, mixed>
     */
    public static function table(Table $table, ?array $order = null): array
    {
        $status = $table->manual_status;

        if ($order !== null) {
            $status = $order['bill_requested'] ? 'bill' : 'occupied';
        }

        return [
            'id' => $table->id, 'location_id' => $table->location_id, 'area_id' => $table->area_id, 'name' => $table->name, 'seats' => (int) $table->seats,
            'x' => (int) $table->x, 'y' => (int) $table->y, 'width' => (int) $table->width, 'height' => (int) $table->height, 'shape' => $table->shape,
            'manual_status' => $table->manual_status, 'status' => $status, 'order' => $order, 'archived_at' => $table->archived_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function station(Station $station): array
    {
        $station->loadMissing('routes');

        return [
            'id' => $station->id, 'location_id' => $station->location_id, 'name' => $station->name, 'kind' => $station->kind, 'printer' => $station->printer,
            'is_default' => (bool) $station->is_default, 'sort' => (int) $station->sort,
            'category_ids' => $station->routes->pluck('item_category_id')->sort()->values()->all(), 'archived_at' => $station->archived_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function group(ModifierGroup $group): array
    {
        $group->loadMissing(['modifiers', 'links']);

        return [
            'id' => $group->id, 'company_id' => $group->company_id, 'name' => $group->name, 'min_select' => (int) $group->min_select, 'max_select' => (int) $group->max_select,
            'currency' => $group->currency,
            'item_ids' => $group->links->pluck('item_id')->filter()->sort()->values()->all(),
            'category_ids' => $group->links->pluck('item_category_id')->filter()->sort()->values()->all(),
            'modifiers' => $group->modifiers->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'price_minor' => (string) $m->price_minor, 'sort' => (int) $m->sort, 'archived_at' => $m->archived_at?->toIso8601String()])->all(),
            'archived_at' => $group->archived_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function settings(string $companyId): array
    {
        $charges = app(Charges::class);
        $all = $charges->for($companyId);

        return [
            'company_id' => $companyId,
            'service_charge_percent' => $all['percent'] ?? '0.0000',
            'service_charge_item_id' => $all['service_item_id'],
            'tip_item_id' => $all['tip_item_id'],
            'tip_presets' => $all['tip_presets'],
        ];
    }
}
