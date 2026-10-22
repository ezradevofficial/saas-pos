<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ADM-05: approve a submitted version as the reviewer saw it, with an optional note (`platform.marketplace.review`). */
class ReviewVersionRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.marketplace.review';

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Security review: the version as the reviewer saw it (its submitted_at); a changed one is refused.
            'submitted_at' => ['required', 'date'],
        ];
    }
}
