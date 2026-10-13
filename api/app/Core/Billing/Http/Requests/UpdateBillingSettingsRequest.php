<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Billing\PlatformSettings;
use App\Core\Platform\Http\Requests\PlatformRequest;

/** SUB-05, ONB-04: the platform's grace period, POS window and trial length (owner, billing). */
class UpdateBillingSettingsRequest extends PlatformRequest
{
    protected ?string $ability = 'billing.manage';

    public function rules(): array
    {
        $rules = [];

        foreach (PlatformSettings::KEYS as $key => [$min, $max]) {
            $rules[$key] = ['sometimes', 'integer', "between:{$min},{$max}"];
        }

        return $rules;
    }
}
