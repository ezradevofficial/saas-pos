<?php

namespace Modules\Inventory\Stock;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use Modules\Inventory\Models\ItemSetting;
use Modules\Inventory\Models\Movement;
use Modules\Inventory\Models\ReorderRule;
use Modules\Inventory\Models\Variant;

/**
 * INV-01, INV-02, INV-11: what Inventory keeps about an item besides its
 * stock: its tracking (fixed once it has movements), its link to a parent
 * item as a variant, and reorder rules per location. Every change is
 * audited by the models (INV-24).
 */
class ItemStock
{
    public function __construct(
        private readonly Places $places,
        private readonly Reorder $reorder,
    ) {}

    public function settings(Item $item, string $tracking, bool $trackExpiry): ItemSetting
    {
        return StockInput::transaction(function () use ($item, $tracking, $trackExpiry) {
            $settings = ItemSetting::query()->where('item_id', $item->id)->lockForUpdate()->first() ?? new ItemSetting(['item_id' => $item->id]);
            $current = $settings->tracking ?? ItemSetting::NONE;

            if ($current !== $tracking && Movement::query()->where('item_id', $item->id)->exists()) {
                throw StockLedger::error(422, 'tracking_locked', field: 'tracking');
            }

            $settings->fill(['tracking' => $tracking, 'track_expiry' => $tracking === ItemSetting::BATCH && $trackExpiry])->save();

            return $settings->refresh();
        });
    }

    /** @param array<string, string> $attributes */
    public function linkVariant(Item $item, ?string $parentId, array $attributes): ?Variant
    {
        return StockInput::transaction(function () use ($item, $parentId, $attributes) {
            $current = Variant::query()->whereNull('archived_at')->where('item_id', $item->id)->lockForUpdate()->first();

            if ($parentId === null) {
                $current?->archive();

                return null;
            }

            if ($parentId === $item->id) {
                throw StockLedger::error(422, 'variant_self', field: 'parent_item_id');
            }

            if (Variant::query()->whereNull('archived_at')->where('item_id', $parentId)->exists()) {
                throw StockLedger::error(422, 'variant_parent_is_variant', field: 'parent_item_id');
            }

            if (Variant::query()->whereNull('archived_at')->where('parent_item_id', $item->id)->exists()) {
                throw StockLedger::error(422, 'variant_has_variants', field: 'parent_item_id');
            }

            ksort($attributes);
            $taken = Variant::query()->whereNull('archived_at')->where('parent_item_id', $parentId)->where('item_id', '<>', $item->id)
                ->whereRaw('attribute_values = ?::jsonb', [json_encode($attributes)])->exists();

            if ($taken) {
                throw StockLedger::error(422, 'variant_attributes_taken', field: 'attributes');
            }

            $variant = $current ?? new Variant(['item_id' => $item->id]);
            $variant->fill(['parent_item_id' => $parentId, 'attribute_values' => $attributes])->save();

            return $variant->refresh();
        });
    }

    /**
     * A rule's level and quantity at a place: made, changed, or archived
     * when the level is null; compared with the stock there at once.
     */
    public function reorderRule(Item $item, string $locationId, ?string $level, ?string $quantity, ?User $by = null): ?ReorderRule
    {
        $place = $this->places->of($locationId) ?? throw StockLedger::error(422, 'location_unknown', field: 'location_id');

        if ($by !== null && ! $by->can('view', $item)) {
            throw StockLedger::error(422, 'item_unknown', field: 'item_id');
        }

        if ($item->type !== 'stock') {
            throw StockLedger::error(422, 'not_a_stock_item', ['item' => $item->name], 'item_id');
        }

        // M3: a shared item, or one of the location's company.
        if (! StockInput::itemAt($item, $place['company_id'])) {
            throw StockLedger::error(422, 'item_not_at_location', ['item' => $item->name], 'item_id');
        }

        return StockInput::transaction(function () use ($item, $locationId, $level, $quantity, $place) {
            $rule = ReorderRule::query()->whereNull('archived_at')->where('item_id', $item->id)->where('location_id', $locationId)->lockForUpdate()->first();

            if ($level === null) {
                $rule?->archive();

                return null;
            }

            $rule ??= new ReorderRule(['item_id' => $item->id, 'location_id' => $locationId, 'company_id' => $place['company_id']]);
            $rule->fill(['reorder_level' => (string) Qty::of($level), 'reorder_qty' => (string) Qty::of($quantity ?? '0')])->save();
            $this->reorder->compare($rule->refresh());

            return $rule->refresh();
        });
    }
}
