<?php

namespace Modules\HR\Http\Requests;

use Modules\HR\Models\ShiftSwap;

/** POST hr/me/swaps/{hr_shift_swap}/accept|decline (HR-31): only the swap's peer answers; anyone else's is not found. */
class SelfSwapActionRequest extends SelfRequest
{
    public function swap(): ShiftSwap
    {
        return $this->route('hr_shift_swap');
    }

    public function authorize(): bool
    {
        abort_unless($this->swap()->peer_employee_id === $this->me()->id, 404);

        return true;
    }
}
