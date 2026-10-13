<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use Illuminate\Validation\Validator;

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

    public function after(): array
    {
        return [fn (Validator $v) => PricedMakeUp::check($v, $this->all(), $this->route('catalogue_bundle'), 'bundle', ['quoted', 'modules', 'addons', 'included_outlets'])];
    }
}
