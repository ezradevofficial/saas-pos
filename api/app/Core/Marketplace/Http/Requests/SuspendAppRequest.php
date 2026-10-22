<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ADM-05: suspend or reinstate an app, with a reason of 10 to 500 characters (`platform.marketplace.review`). */
class SuspendAppRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.marketplace.review';

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
