<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\RosterShiftList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Models\ShiftSwap;

/** GET hr/shift-swaps (HR-19). */
class ListSwapsRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new RosterShiftList;
    }

    protected function viewPermission(): string
    {
        return 'hr.roster.view';
    }

    protected function statuses(): array
    {
        return ShiftSwap::STATUSES;
    }
}
