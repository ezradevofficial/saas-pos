<?php

namespace Modules\POS\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\POS\Http\Lists\ShiftList;
use Modules\POS\Models\Shift;

/** GET pos/shifts: `pos.shift.view`; `?search=` matches the device or the cashier who opened it (POS-12). */
class ListShiftsRequest extends FormRequest
{
    use ListsPosRecords;

    public function list(): ListDefinition
    {
        return new ShiftList;
    }

    protected function viewPermission(): string
    {
        return 'pos.shift.view';
    }

    protected function statuses(): array
    {
        return Shift::STATUSES;
    }
}
