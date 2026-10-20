<?php

namespace App\Core\CountryPacks\Http\Requests;

use App\Core\CountryPacks\PackDocuments;
use App\Core\Platform\Http\Requests\PlatformRequest;
use Illuminate\Validation\Rule;

/** ADM-04: country pack releases in the console (`platform.packs.view`; filters by country and kind). */
class PackReleaseRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.packs.view';

    public function rules(): array
    {
        return [
            'country' => ['sometimes', 'nullable', 'string', 'regex:/^[A-Z]{2}\z/'],
            'kind' => ['sometimes', 'nullable', 'string', Rule::in(array_keys(PackDocuments::KINDS))],
        ];
    }
}
