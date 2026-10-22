<?php

namespace App\Core\Marketplace\Http\Requests;

/** ADM-05: reject a submitted version with the reason its developer reads (10 to 500 characters). */
class RejectVersionRequest extends ReviewVersionRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }
}
