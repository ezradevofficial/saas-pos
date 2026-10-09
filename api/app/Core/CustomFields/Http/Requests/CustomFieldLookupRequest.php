<?php

namespace App\Core\CustomFields\Http\Requests;

use App\Core\CustomFields\CustomFieldEntities;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * CF-01: candidates for a lookup field: `?target=` (a lookup target),
 * `?search=` or `?id=`. Any signed-in user may ask: the answer holds only
 * records the user may see (LookupTarget), so it reveals nothing more
 * than the target's own lists.
 */
class CustomFieldLookupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target' => ['required', 'string', Rule::in(array_keys(app(CustomFieldEntities::class)->lookups()))],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'id' => ['sometimes', 'uuid'],
        ];
    }
}
