<?php

namespace App\Core\CountryPacks\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ADM-04 (review L7): load the content before a published release as a new draft (`platform.packs.manage`). */
class SupersedePackReleaseRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.packs.manage';
}
