<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\PunchList;
use Modules\HR\Http\Requests\Concerns\ListsHr;

/** GET hr/punches (HR-22): punches of the employees at the reader's places. */
class ListPunchesRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new PunchList;
    }

    protected function viewPermission(): string
    {
        return 'hr.attendance.view';
    }
}
