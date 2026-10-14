<?php

namespace Modules\HR\Http\Requests;

/** POST hr/me/swaps (HR-31, HR-19): a swap of one of the employee's own shifts. */
class SelfSwapRequest extends SelfRequest
{
    public function rules(): array
    {
        return SwapRules::rules();
    }
}
