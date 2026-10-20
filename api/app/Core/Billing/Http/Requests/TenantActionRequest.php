<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ADM-01c: suspend or reactivate a tenant (`platform.tenants.manage`), with a reason. */
class TenantActionRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.tenants.manage';

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:500']];
    }
}
