<?php

namespace App\Core\Platform\SupportAccess\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** RBAC-13: staff's own support requests and sessions (`platform.support.request`). */
class PlatformSupportRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.support.request';
}
