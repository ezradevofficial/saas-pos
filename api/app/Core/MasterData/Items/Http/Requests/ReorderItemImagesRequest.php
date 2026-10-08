<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\Item;

/** MD-02: put an item's images in a new order (`core.item.edit`): `image_ids`, every image of the item once. */
class ReorderItemImagesRequest extends ItemRequest
{
    protected string $ability = 'update';

    public function rules(): array
    {
        return [
            'image_ids' => ['required', 'array', 'max:'.Item::MAX_IMAGES],
            'image_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    public function attributes(): array
    {
        return ['image_ids' => __('core.item.attributes.image_ids')];
    }
}
