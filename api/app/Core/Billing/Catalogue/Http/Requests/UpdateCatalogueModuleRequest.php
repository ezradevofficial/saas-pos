<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Validation\Validator;

class UpdateCatalogueModuleRequest extends ManageCatalogueRequest
{
    use NameRules;

    public function rules(): array
    {
        return [
            'key' => ['prohibited'],
            'kind' => ['prohibited'],
            'unit' => ['prohibited'],
            ...$this->nameRules(false),
            ...ModuleCompositionRules::rules(),
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function after(): array
    {
        return [fn (Validator $v) => ModuleCompositionRules::check($v, $this, (string) $this->route('catalogue_module')?->key)];
    }
}
