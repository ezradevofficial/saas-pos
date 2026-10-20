<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** SUB-05: staff (owner, billing) give one tenant its own grace period in days, or null for the platform's. */
class GraceOverrideRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.billing.manage';

    public function rules(): array
    {
        return ['grace_days' => ['present', 'nullable', 'integer', 'between:0,90']];
    }
}
