<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

class UpdateCatalogueBundleRequest extends ManageCatalogueRequest
{
    use NameRules;

    public function rules(): array
    {
        return [
            'key' => ['prohibited'],
            ...$this->nameRules(false),
            ...BundleCompositionRules::rules(false),
        ];
    }
}
