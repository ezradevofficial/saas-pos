<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/**
 * ADM-01: the tenants list and a tenant's detail (every staff role,
 * `platform.tenants.view`). Search filters are validated here.
 */
class PlatformTenantsRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.tenants.view';

    public function authorize(): bool
    {
        // Review L3: searching by an Owner's email is for support and compliance (and platform owners).
        return parent::authorize() && (! $this->filled('owner_email') || (bool) $this->staff()?->allows('platform.tenants.owner_search'));
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'owner_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:60'],
            'status' => ['sometimes', 'nullable', 'string', 'in:trial,active,past_due,read_only,suspended,cancelled'],
            'module' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z_]+\z/'],
        ];
    }
}
