<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** SUB-01: a new catalogue module, extra or add-on. Key, kind and unit are set once. */
class StoreCatalogueModuleRequest extends ManageCatalogueRequest
{
    use NameRules;

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', Rule::unique('catalogue_modules', 'key')],
            'kind' => ['required', Rule::in(ModuleOffer::KINDS)],
            'unit' => ['required', Rule::in(ModuleOffer::UNITS)],
            ...$this->nameRules(),
            ...ModuleCompositionRules::rules(),
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => ModuleCompositionRules::check($v, $this, (string) $this->input('key'))];
    }
}
