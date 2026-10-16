<?php

namespace App\Core\Rbac\Http\Requests;

use App\Core\Rbac\LimitRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT roles/{role}/limits (RBAC-06, owner decision A): `core.role.edit` at
 * tenant scope. `limits` maps limit keys to a value or null (removes it);
 * keys left out stay. Percentages are 0 to 100 with up to 2 decimals;
 * amounts (base currency, major units) are 0 or more with up to 4.
 */
class UpdateRoleLimitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    public function rules(): array
    {
        $rules = ['limits' => ['required', 'array:'.implode(',', LimitRules::KEYS), 'min:1']];

        foreach (LimitRules::KEYS as $key) {
            $rules["limits.{$key}"] = in_array($key, LimitRules::PERCENT_KEYS, true)
                ? ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100']
                : ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'];
        }

        return $rules;
    }

    /** @return array<string, string|null> the limits as decimal strings */
    public function limits(): array
    {
        return array_map(fn ($value) => $value === null ? null : (string) $value, $this->validated('limits'));
    }
}
