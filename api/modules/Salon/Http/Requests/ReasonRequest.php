<?php

namespace Modules\Salon\Http\Requests;

/** A step on a bound record that needs a reason (cancel, no-show, waive a fee, reject a batch). */
class ReasonRequest extends BoundRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:200'],
        ];
    }
}
