<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;
use Illuminate\Validation\Rule;

/** ADM-05: the review queue and an app's versions (`platform.marketplace.view`). */
class PlatformMarketplaceRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.marketplace.view';

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['submitted', 'listed', 'suspended', 'all'])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
