<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Validation\Rule;

/** SUB-01: a bundle's names, modules, add-ons and outlets. */
class StoreCatalogueBundleRequest extends ManageCatalogueRequest
{
    use NameRules;

    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/', Rule::unique('catalogue_bundles', 'key')],
            ...$this->nameRules(),
            ...BundleCompositionRules::rules(true),
        ];
    }
}
