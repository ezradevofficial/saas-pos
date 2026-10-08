<?php

namespace App\Core\MasterData\Items\Http\Resources;

use App\Core\MasterData\Items\ItemCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item category (MD-02). Fields hidden from the user by field rules on
 * `item_category` (RBAC-05) are left out, as in its history.
 *
 * @mixin ItemCategory
 */
class ItemCategoryResource extends JsonResource
{
    public const FIELD_RULES = 'item_category';

    public function toArray(Request $request): array
    {
        $fields = [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'shared' => $this->isShared(),
            'parent_id' => $this->parent_id,
            'name' => app()->getLocale() === 'fr' ? ($this->name_fr ?? $this->name_en) : ($this->name_en ?? $this->name_fr),
            'name_en' => $this->name_en,
            'name_fr' => $this->name_fr,
            'colour' => $this->colour,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];

        return HidesFields::apply($request, self::FIELD_RULES, $fields, ['name' => ['name_en', 'name_fr']]);
    }
}
