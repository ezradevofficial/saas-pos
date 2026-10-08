<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemSharing;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-02 item category validation: a name in English or French; a parent
 * that is active, in the same scope (the category's company, or shared),
 * and not the category itself or beneath it; `colour` a design token name
 * (LAY-05), never a colour value. The company follows the items sharing
 * mode (TEN-08) and is set at creation only.
 */
final class ItemCategoryRules
{
    public const COLOUR_PATTERN = '/^[a-z][a-z0-9\-]{0,39}\z/';

    public const MAX_DEPTH = 6;

    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $rules = [
            'parent_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('archived_at')],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:100'],
            'colour' => ['sometimes', 'nullable', 'string', 'regex:'.self::COLOUR_PATTERN],
        ];

        if (! $updating) {
            $rules['company_id'] = ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')];
        }

        return $rules;
    }

    public static function validateCategory(Validator $validator, array $input, ?ItemCategory $category): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $companyId = $category === null ? ($input['company_id'] ?? null) : $category->company_id;

        if ($category === null) {
            ItemSharing::validate($validator, $companyId);
        }

        $nameEn = array_key_exists('name_en', $input) ? $input['name_en'] : $category?->name_en;
        $nameFr = array_key_exists('name_fr', $input) ? $input['name_fr'] : $category?->name_fr;

        if (blank($nameEn) && blank($nameFr)) {
            $validator->errors()->add('name_en', __('core.item_category.name_required'));
        }

        $parentId = $input['parent_id'] ?? null;

        if ($parentId === null) {
            return;
        }

        $parent = ItemCategory::query()->findOrFail($parentId);

        if ($parent->company_id !== $companyId) {
            $validator->errors()->add('parent_id', __('core.item_category.parent_other_scope'));
        } elseif ($category !== null && $category->isAncestorOf($parentId)) {
            $validator->errors()->add('parent_id', __('core.item_category.parent_cycle'));
        } elseif (self::depth($parent) + 1 + ($category === null ? 0 : self::height($category)) > self::MAX_DEPTH) {
            $validator->errors()->add('parent_id', __('core.item_category.too_deep', ['max' => self::MAX_DEPTH]));
        }
    }

    /** Levels from the root down to $category (a root is 1). */
    private static function depth(ItemCategory $category): int
    {
        $depth = 1;

        for ($parentId = $category->parent_id; $parentId !== null && $depth <= self::MAX_DEPTH; $depth++) {
            $parentId = ItemCategory::query()->whereKey($parentId)->value('parent_id');
        }

        return $depth;
    }

    /** Levels below $category (a leaf is 0). */
    private static function height(ItemCategory $category): int
    {
        $height = 0;

        for ($level = [$category->id]; $height <= self::MAX_DEPTH; $height++) {
            $level = ItemCategory::query()->whereIn('parent_id', $level)->pluck('id')->all();

            if ($level === []) {
                break;
            }
        }

        return $height;
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect(['company_id' => 'company', 'parent_id' => 'parent', 'name_en' => 'name_en', 'name_fr' => 'name_fr', 'colour' => 'colour'])
            ->map(fn (string $key) => __("core.item_category.attributes.{$key}"))->all();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['colour.regex' => __('core.item_category.colour_invalid')];
    }
}
