<?php

namespace App\Core\Billing\Http\Requests;

use App\Core\Platform\Http\Requests\PlatformRequest;

/** ADM-02: approve, reject or end a deal (finance: `platform.deals.approve`), with an optional note. */
class DecideDealRequest extends PlatformRequest
{
    protected ?string $ability = 'platform.deals.approve';

    public function rules(): array
    {
        return [
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // `end` only: the deal's last day (today by default).
            'last_day' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:'.now()->utc()->toDateString()],
        ];
    }
}
