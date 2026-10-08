<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Lists\Http\ListsRecords;
use App\Core\Lists\ListDefinition;
use App\Core\MasterData\Items\Barcode;
use App\Core\MasterData\Items\Http\Lists\ItemList;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\ItemPolicy;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * MD-02: list items the user can view (`core.item.view` anywhere):
 * `?search=` (code prefix, English or French name, or an exact barcode),
 * `?barcode=` (exact, normalised: the POS lookup), `?category=` (that
 * category and those beneath it), `?type=`, `?status`, `?per_page`,
 * `?sort` and an export (`?format`, `?columns[]`; ItemList, EXP-01).
 */
class ListItemsRequest extends FormRequest
{
    use ListsRecords;

    public function list(): ListDefinition
    {
        return new ItemList;
    }

    public function authorize(): bool
    {
        return app(ItemPolicy::class)->viewAny($this->user());
    }

    public function rules(): array
    {
        return [
            ...$this->listRules(),
            'search' => ['sometimes', 'string', 'max:100'],
            'barcode' => ['sometimes', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (Barcode::normalise($value) === null) {
                    $fail(__('core.item.barcode_invalid'));
                }
            }],
            'category' => ['sometimes', 'uuid', Rule::exists('item_categories', 'id')],
            'type' => ['sometimes', 'string', Rule::in(Item::TYPES)],
        ];
    }

    public function attributes(): array
    {
        return ['category' => __('core.item.attributes.category'), 'type' => __('core.item.attributes.type'), 'barcode' => __('core.item.attributes.barcode')];
    }
}
