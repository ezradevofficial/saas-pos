<?php

namespace App\Core\CountryPacks\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/**
 * ADM-04c: approve or reject (`platform.packs.approve`), or publish
 * (`platform.packs.publish`) a release. The controller names the ability.
 */
class DecidePackReleaseRequest extends PlatformRequest
{
    public function authorize(): bool
    {
        $this->ability = $this->route()?->getActionMethod() === 'publish' ? 'platform.packs.publish' : 'platform.packs.approve';

        return parent::authorize();
    }

    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:500']];
    }
}
