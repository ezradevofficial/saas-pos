<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Marketplace\Models\MarketplaceAppVersion;
use Illuminate\Validation\Rule;

/** MKT-01: the listing (listed apps only), by name and category. */
class ListMarketplaceAppsRequest extends MarketplaceRequest
{
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', Rule::in(MarketplaceAppVersion::CATEGORIES)],
        ];
    }
}
