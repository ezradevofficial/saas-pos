<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\ItemImage;
use App\Core\MasterData\Items\ItemPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * MD-02: delete an item image (`core.item.edit` on its item). An image of
 * an item the user does not reach is not found (RBAC-04).
 */
class ItemImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        $policy = app(ItemPolicy::class);
        $item = $this->itemImage()->item;

        abort_unless($item !== null && $policy->reach($this->user(), $item), 404);

        return $policy->update($this->user(), $item);
    }

    public function rules(): array
    {
        return [];
    }

    public function itemImage(): ItemImage
    {
        return $this->route('item_image');
    }
}
