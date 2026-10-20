<?php

namespace App\Core\CountryPacks\Http\Requests;

use App\Core\CountryPacks\PackDocuments;
use App\Core\Platform\Http\Requests\PlatformRequest;
use Illuminate\Validation\Rule;

/**
 * ADM-04a: load a pack document (`platform.packs.manage`): a country, a
 * kind, the JSON document (an object, at most 512 KB once encoded), its
 * effective date and notes. The document's own validation happens in
 * PackValidator and is stored with the release.
 */
class LoadPackReleaseRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.packs.manage';

    public function rules(): array
    {
        return [
            'country' => ['required', 'string', Rule::in(['KE', 'CD'])],
            'kind' => ['required', 'string', Rule::in(array_keys(PackDocuments::KINDS))],
            'content' => ['required', 'array', function (string $attribute, mixed $value, \Closure $fail) {
                if (array_is_list((array) $value) || strlen((string) json_encode($value)) > 512 * 1024) {
                    $fail(__('country_packs.releases.content_shape'));
                }
            }],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
