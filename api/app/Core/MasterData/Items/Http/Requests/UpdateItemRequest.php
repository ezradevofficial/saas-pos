<?php

namespace App\Core\MasterData\Items\Http\Requests;

use App\Core\Rbac\Http\Requests\GuardsFieldRules;
use Illuminate\Validation\Validator;

/**
 * MD-02: change an item (`core.item.edit`). `uoms` and `barcodes` given
 * replace the stored ones. Moving it to another company (items kept per
 * company) needs `core.item.edit` covering that company too (TEN-08).
 */
class UpdateItemRequest extends ItemRequest
{
    use GuardsFieldRules;

    /** RBAC-05: input refused when its field is hidden or read-only for the user. */
    protected string $fieldRulesResource = 'item';

    /** @var array<string, list<string>> input key => field rule names it writes */
    protected array $fieldRulesInputs = ['name_en' => ['name'], 'name_fr' => ['name']];

    protected string $ability = 'update';

    public function rules(): array
    {
        return ItemRules::rules(updating: true);
    }

    public function after(): array
    {
        return [fn (Validator $validator) => ItemRules::validateItem(
            $validator, $validator->errors()->isEmpty() ? $validator->validated() : [], $this->item(), $this->user(),
        )];
    }

    public function attributes(): array
    {
        return ItemRules::attributeNames();
    }

    public function messages(): array
    {
        return ItemRules::messages();
    }
}
