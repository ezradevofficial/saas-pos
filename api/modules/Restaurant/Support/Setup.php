<?php

namespace Modules\Restaurant\Support;

use Illuminate\Support\Facades\DB;
use Modules\Restaurant\Models\Modifier;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Models\ModifierLink;
use Modules\Restaurant\Models\Setting;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\StationRoute;

/**
 * REST-02, REST-07, REST-08, REST-13: the set-up writes that touch more than
 * one row (stations with their routes, modifier groups with their modifiers
 * and links, settings), each in one transaction. Audited through the models
 * (AUD-01); join rows (routes, links) are replaced, not business records.
 */
class Setup
{
    /** @param array<string, mixed> $data */
    public function station(?Station $station, array $data, ?array $place = null, ?string $locationId = null): Station
    {
        return Errors::transaction(function () use ($station, $data, $place, $locationId) {
            if ($station === null) {
                $station = new Station(['location_id' => $locationId, ...$place, 'kind' => 'kitchen']);
            }

            $station->fill(array_intersect_key($data, array_flip(['name', 'kind', 'printer', 'sort'])));
            $station->is_default = (bool) ($data['is_default'] ?? $station->is_default);

            if ($station->is_default) {
                // One default per location (REST-08): the previous one steps down.
                Station::query()->where('location_id', $station->location_id)->where('is_default', true)->when($station->exists, fn ($q) => $q->whereKeyNot($station->id))->get()
                    ->each(function (Station $other) {
                        $other->is_default = false;
                        $other->save();
                    });
            }

            $station->save();

            if (array_key_exists('category_ids', $data)) {
                $ids = array_values(array_unique($data['category_ids']));
                // A category is routed to one station per location: it moves here.
                StationRoute::query()->where('location_id', $station->location_id)->where(fn ($q) => $q->where('station_id', $station->id)->orWhereIn('item_category_id', $ids))->delete();

                foreach ($ids as $category) {
                    StationRoute::create(['location_id' => $station->location_id, 'station_id' => $station->id, 'item_category_id' => $category]);
                }
            }

            return $station;
        });
    }

    /** @param array<string, mixed> $data */
    public function group(?ModifierGroup $group, array $data, ?string $companyId = null): ModifierGroup
    {
        return Errors::transaction(function () use ($group, $data, $companyId) {
            $group ??= new ModifierGroup(['company_id' => $companyId]);
            $group->fill(array_intersect_key($data, array_flip(['name', 'min_select', 'max_select', 'currency'])));
            $group->save();

            if (array_key_exists('modifiers', $data)) {
                $keep = [];

                foreach ($data['modifiers'] as $index => $row) {
                    $modifier = ! empty($row['id']) ? Modifier::query()->where('group_id', $group->id)->whereKey($row['id'])->first() : null;

                    if (! empty($row['id']) && $modifier === null) {
                        throw Errors::make(422, 'modifier_unknown', field: "modifiers.{$index}.id");
                    }

                    $modifier ??= new Modifier(['group_id' => $group->id]);
                    $modifier->fill(['name' => $row['name'], 'price_minor' => $row['price_minor'], 'sort' => $row['sort'] ?? $index, 'archived_at' => null]);
                    $modifier->save();
                    $keep[] = $modifier->id;
                }

                // A modifier left out is archived: old orders keep the name and price they were sold at.
                Modifier::query()->where('group_id', $group->id)->whereNull('archived_at')->whereNotIn('id', $keep)->get()->each->archive();
            }

            if (array_key_exists('item_ids', $data) || array_key_exists('category_ids', $data)) {
                $items = $data['item_ids'] ?? ModifierLink::query()->where('group_id', $group->id)->whereNotNull('item_id')->pluck('item_id')->all();
                $categories = $data['category_ids'] ?? ModifierLink::query()->where('group_id', $group->id)->whereNotNull('item_category_id')->pluck('item_category_id')->all();
                ModifierLink::query()->where('group_id', $group->id)->delete();

                foreach (array_unique($items) as $item) {
                    ModifierLink::create(['group_id' => $group->id, 'item_id' => $item]);
                }

                foreach (array_unique($categories) as $category) {
                    ModifierLink::create(['group_id' => $group->id, 'item_category_id' => $category]);
                }
            }

            return $group;
        });
    }

    /** @param array<string, mixed> $data */
    public function settings(string $companyId, array $data): Setting
    {
        $setting = Setting::query()->where('company_id', $companyId)->first() ?? new Setting(['company_id' => $companyId]);

        foreach (['service_charge_item_id', 'tip_item_id'] as $key) {
            if (! empty($data[$key])) {
                $type = DB::table('items')->where('id', $data[$key])->value('type');

                if ($type === 'stock') {
                    throw Errors::make(422, 'item_must_not_be_stock', field: $key);
                }
            }
        }

        $setting->fill(array_intersect_key($data, array_flip(['service_charge_percent', 'service_charge_item_id', 'tip_item_id'])));

        if (array_key_exists('tip_presets', $data)) {
            $setting->tip_presets = array_values(array_unique(array_map('intval', $data['tip_presets'])));
        }

        $setting->save();

        return $setting;
    }
}
