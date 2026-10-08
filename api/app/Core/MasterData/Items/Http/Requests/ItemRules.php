<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Barcode;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemBarcode;
use App\Core\MasterData\Items\ItemCategory;
use App\Core\MasterData\Items\ItemPolicy;
use App\Core\MasterData\Items\ItemSharing;
use App\Core\MasterData\Items\ItemUom;
use App\Core\MasterData\Taxes\TaxCategory;
use Brick\Math\BigDecimal;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MD-02 item validation and normalisation, shared by create and update.
 *
 * - `company_id` follows the items sharing mode (TEN-08). Moving an item to
 *   another company needs `core.item.edit` covering that company.
 * - The category and tax category are active and in the item's scope (the
 *   item's company, or shared for a shared item).
 * - `uoms` lists the other units: never the base unit (factor 1,
 *   implicit), each once, factor > 0 with at most 6 decimals, at most one
 *   sales and one purchase default. `barcodes` lists {barcode, uom_id}:
 *   normalised (Barcode), each once, for the base unit (uom_id null or the
 *   base) or one of the item's units. Lists given replace the stored ones.
 * - Uniqueness of the code and barcodes in the sharing scope is checked
 *   under the sharing lock (ItemUniqueness), not here.
 */
final class ItemRules
{
    /** Letters, digits and . _ - /; no spaces (surrounding ones are trimmed). */
    public const CODE_PATTERN = '/^[\pL\pN][\pL\pN._\-\/]{0,39}\z/u';

    /** Up to 12 integer digits and 6 decimals (numeric(18,6)). */
    public const FACTOR_PATTERN = '/^\d{1,12}(\.\d{1,6})?\z/';

    public const MAX_UOMS = 10;

    public const MAX_BARCODES = 20;

    /** @return array<string, list<mixed>> */
    public static function rules(bool $updating): array
    {
        $required = $updating ? ['sometimes', 'required'] : ['required'];
        $activeUom = Rule::exists('uoms', 'id')->whereNull('archived_at');

        return [
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')->whereNull('archived_at')],
            'code' => [...$required, 'string', 'max:40', 'regex:'.self::CODE_PATTERN],
            'name_en' => ['sometimes', 'nullable', 'string', 'max:255'],
            'name_fr' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('item_categories', 'id')->whereNull('archived_at')],
            'type' => [...$required, 'string', Rule::in(Item::TYPES)],
            'base_uom_id' => [...$required, 'uuid', $activeUom],
            'tax_category_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('tax_categories', 'id')->whereNull('archived_at')],
            'uoms' => ['sometimes', 'array', 'max:'.self::MAX_UOMS],
            'uoms.*' => ['array:uom_id,factor,is_sales_default,is_purchase_default'],
            'uoms.*.uom_id' => ['required', 'uuid', 'distinct', $activeUom],
            'uoms.*.factor' => ['required', 'regex:'.self::FACTOR_PATTERN],
            'uoms.*.is_sales_default' => ['sometimes', 'boolean'],
            'uoms.*.is_purchase_default' => ['sometimes', 'boolean'],
            'barcodes' => ['sometimes', 'array', 'max:'.self::MAX_BARCODES],
            'barcodes.*' => ['array:barcode,uom_id'],
            'barcodes.*.barcode' => ['required', 'string', 'max:64'],
            'barcodes.*.uom_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** Cross-field checks once the fields are valid. */
    public static function validateItem(Validator $validator, array $input, ?Item $item, User $user): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $item?->company_id;
        ItemSharing::validate($validator, $companyId);

        if ($item !== null && $companyId !== null && $companyId !== $item->company_id && ! app(ItemPolicy::class)->editIn($user, $companyId)) {
            $validator->errors()->add('company_id', __('core.item.company_not_reached'));
        }

        $nameEn = array_key_exists('name_en', $input) ? $input['name_en'] : $item?->name_en;
        $nameFr = array_key_exists('name_fr', $input) ? $input['name_fr'] : $item?->name_fr;

        if (blank($nameEn) && blank($nameFr)) {
            $validator->errors()->add('name_en', __('core.item.name_required'));
        }

        $categoryId = array_key_exists('category_id', $input) ? $input['category_id'] : $item?->category_id;

        if ($categoryId !== null && ItemCategory::query()->whereKey($categoryId)->value('company_id') !== $companyId) {
            $validator->errors()->add('category_id', __('core.item.category_other_scope'));
        }

        $taxCategoryId = array_key_exists('tax_category_id', $input) ? $input['tax_category_id'] : $item?->tax_category_id;

        if ($taxCategoryId !== null && TaxCategory::query()->whereKey($taxCategoryId)->value('company_id') !== $companyId) {
            $validator->errors()->add('tax_category_id', __('core.item.tax_category_other_scope'));
        }

        self::validateUnits($validator, $input, $item);
    }

    private static function validateUnits(Validator $validator, array $input, ?Item $item): void
    {
        $baseUomId = $input['base_uom_id'] ?? $item?->base_uom_id;
        $uoms = array_key_exists('uoms', $input)
            ? array_values($input['uoms'])
            : ($item === null ? [] : ItemUom::query()->where('item_id', $item->id)->get()->map(fn (ItemUom $u) => $u->only(['uom_id', 'factor', 'is_sales_default', 'is_purchase_default']))->all());

        foreach ($uoms as $index => $uom) {
            if ($uom['uom_id'] === $baseUomId) {
                $validator->errors()->add(array_key_exists('uoms', $input) ? "uoms.{$index}.uom_id" : 'base_uom_id', __('core.item.base_in_units'));
            }

            if (array_key_exists('uoms', $input) && BigDecimal::of((string) $uom['factor'])->isZero()) {
                $validator->errors()->add("uoms.{$index}.factor", __('core.item.factor_positive'));
            }
        }

        foreach (['is_sales_default' => 'sales_default_once', 'is_purchase_default' => 'purchase_default_once'] as $flag => $message) {
            if (count(array_filter($uoms, fn (array $u) => (bool) ($u[$flag] ?? false))) > 1) {
                $validator->errors()->add('uoms', __("core.item.{$message}"));
            }
        }

        $unitIds = [$baseUomId, ...array_column($uoms, 'uom_id')];

        if (array_key_exists('barcodes', $input)) {
            $seen = [];

            foreach (array_values($input['barcodes']) as $index => $entry) {
                $barcode = Barcode::normalise($entry['barcode']);

                if ($barcode === null) {
                    $validator->errors()->add("barcodes.{$index}.barcode", __('core.item.barcode_invalid'));

                    continue;
                }

                if (isset($seen[$barcode])) {
                    $validator->errors()->add("barcodes.{$index}.barcode", __('core.item.barcode_repeated'));
                }

                $seen[$barcode] = true;
                $uomId = $entry['uom_id'] ?? null;

                if ($uomId !== null && ! in_array($uomId, $unitIds, true)) {
                    $validator->errors()->add("barcodes.{$index}.uom_id", __('core.item.barcode_unit'));
                }
            }
        } elseif ($item !== null && ItemBarcode::query()->where('item_id', $item->id)->whereNotNull('uom_id')->whereNotIn('uom_id', $unitIds)->exists()) {
            $validator->errors()->add('uoms', __('core.item.barcode_unit_removed'));
        }
    }

    /** Item attributes from validated input (only the fields given; company_id set by the caller). */
    public static function attributes(array $data): array
    {
        return array_intersect_key($data, array_flip(['code', 'name_en', 'name_fr', 'category_id', 'type', 'base_uom_id', 'tax_category_id']));
    }

    /**
     * The barcodes given, normalised; null when the request has none.
     *
     * @return list<array{barcode: string, uom_id: ?string}>|null
     */
    public static function barcodes(array $data, string $baseUomId): ?array
    {
        if (! array_key_exists('barcodes', $data)) {
            return null;
        }

        return array_map(fn (array $entry) => [
            'barcode' => Barcode::normalise($entry['barcode']),
            // The base unit is stored as null (it is implicit).
            'uom_id' => ($entry['uom_id'] ?? null) === $baseUomId ? null : ($entry['uom_id'] ?? null),
        ], array_values($data['barcodes']));
    }

    /** @return array<string, string> */
    public static function attributeNames(): array
    {
        return collect([
            'company_id' => 'company', 'code' => 'code', 'name_en' => 'name_en', 'name_fr' => 'name_fr',
            'category_id' => 'category', 'type' => 'type', 'base_uom_id' => 'base_uom', 'tax_category_id' => 'tax_category',
            'uoms' => 'uoms', 'uoms.*.uom_id' => 'uom', 'uoms.*.factor' => 'factor', 'barcodes' => 'barcodes',
            'barcodes.*.barcode' => 'barcode', 'barcodes.*.uom_id' => 'uom',
        ])->map(fn (string $key) => __("core.item.attributes.{$key}"))->all();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'code.regex' => __('core.item.code_invalid'),
            'uoms.*.factor.regex' => __('core.item.factor_invalid'),
        ];
    }
}
