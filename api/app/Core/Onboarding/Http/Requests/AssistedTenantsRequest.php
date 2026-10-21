<?php

namespace App\Core\Onboarding\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ONB-08: staff who create tenants for customers (owner, support): resend an invitation, see the tenants waiting for handover. */
class AssistedTenantsRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.tenants.create';
}
