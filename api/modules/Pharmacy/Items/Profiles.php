<?php

namespace Modules\Pharmacy\Items;

use App\Core\Audit\Auditor;
use App\Core\MasterData\Items\Item;
use Illuminate\Support\Collection;
use Modules\Pharmacy\Models\ItemProfile;
use Modules\Pharmacy\Support\PharmacyError;

/**
 * PHA-03, PHA-20, PHA-21: the item's pharmacy profile. An item without a
 * profile is a plain retail item to Pharmacy: not tracked by lot, no rules.
 */
class Profiles
{
    public function __construct(private readonly Auditor $auditor) {}

    public function of(string $itemId): ?ItemProfile
    {
        return ItemProfile::query()->where('item_id', $itemId)->first();
    }

    /**
     * @param  list<string>  $itemIds
     * @return Collection<string, ItemProfile> by item id
     */
    public function many(array $itemIds): Collection
    {
        return ItemProfile::query()->whereIn('item_id', array_values(array_unique($itemIds)))->get()->keyBy('item_id');
    }

    /**
     * Create or change the profile of $item (audited).
     *
     * @param  array<string, mixed>  $data  class, product_kind, batch_tracked, restricted_sale, usage_notes, schedule_note, withdrawal_days (any subset)
     */
    public function save(Item $item, array $data): ItemProfile
    {
        if ($item->type !== 'stock') {
            throw PharmacyError::make(422, 'item_not_stock', field: 'item_id');
        }

        $profile = $this->of($item->id) ?? new ItemProfile(['item_id' => $item->id]);
        $before = $profile->exists ? $profile->only(array_keys($data)) : null;
        $profile->fill($data)->save();
        $this->auditor->record($before === null ? 'pharmacy.item.create' : 'pharmacy.item.update', $profile, $before, $profile->only(array_keys($data)));

        return $profile->refresh();
    }
}
