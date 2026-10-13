<?php

namespace App\Core\Billing\Catalogue\Http\Requests;

use App\Core\Billing\Catalogue\Pricing\ModuleOffer;
use Illuminate\Validation\Rule;

final class BundleCompositionRules
{
    /** @return array<string, list<mixed>> */
    public static function rules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'modules' => [$presence, 'array', 'min:1', 'max:40'],
            'modules.*' => ['string', 'distinct', Rule::exists('catalogue_modules', 'key')->where('kind', ModuleOffer::KIND_MODULE)],
            // SUB-01 add-ons: which add-ons may be bought with the bundle (Brand Pack with Starter).
            'addons' => ['sometimes', 'array', 'max:20'],
            'addons.*' => ['string', 'distinct', Rule::exists('catalogue_modules', 'key')->where('kind', ModuleOffer::KIND_ADDON)],
            'included_outlets' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'quoted' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
