<?php

namespace App\Core\Platform\Http\Requests;

/** ADM-07: staff accounts (platform owners only). */
class ManageStaffRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.staff.manage';
}
