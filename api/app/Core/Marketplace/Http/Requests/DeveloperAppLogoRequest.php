<?php

namespace App\Core\Marketplace\Http\Requests;

/** MKT-01: the draft's logo: a square PNG, JPEG or WebP image, at most `marketplace.apps.logo_max_kb`. */
class DeveloperAppLogoRequest extends DeveloperRequest
{
    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:'.(int) config('marketplace.apps.logo_max_kb', 512), 'dimensions:min_width=64,max_width=2048,ratio=1'],
        ];
    }
}
