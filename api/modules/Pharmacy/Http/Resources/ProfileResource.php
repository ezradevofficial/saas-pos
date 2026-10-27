<?php

namespace Modules\Pharmacy\Http\Resources;

use App\Core\MasterData\Items\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Pharmacy\Models\ItemProfile;

/**
 * PHA-03: an item's pharmacy profile. An item without one shows the
 * plain-retail defaults (`configured: false`, see forItem()).
 *
 * @mixin ItemProfile
 */
class ProfileResource extends JsonResource
{
    /** The stored profile of $item, else an unsaved one with the plain-retail defaults. */
    public static function forItem(Item $item, ?ItemProfile $profile): self
    {
        $profile ??= new ItemProfile(['item_id' => $item->id, 'class' => ItemProfile::OTC, 'product_kind' => 'human', 'batch_tracked' => false, 'restricted_sale' => false]);
        $profile->setRelation('item', $item);

        return new self($profile);
    }

    public function toArray(Request $request): array
    {
        /** @var ItemProfile $profile */
        $profile = $this->resource;

        return [
            'item' => Refs::item($profile->item),
            'configured' => $profile->exists,
            'class' => $profile->class,
            'product_kind' => $profile->product_kind,
            'batch_tracked' => (bool) $profile->batch_tracked,
            'restricted_sale' => (bool) $profile->restricted_sale,
            'usage_notes' => $profile->usage_notes,
            'schedule_note' => $profile->schedule_note,
            'withdrawal_days' => $profile->withdrawal_days,
        ];
    }
}
