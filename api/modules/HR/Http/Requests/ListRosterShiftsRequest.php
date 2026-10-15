<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\RosterShiftList;
use Modules\HR\Http\Requests\Concerns\ListsHr;

/** GET hr/roster-shifts (HR-18): the week grid's shifts (`?from=`, `?to=`, at most 31 days; this week by default). */
class ListRosterShiftsRequest extends FormRequest
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
        return ['planned', 'cancelled'];
    }
}
