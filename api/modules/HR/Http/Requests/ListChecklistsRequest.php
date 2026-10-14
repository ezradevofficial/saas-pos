<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\RosterShiftList;
use Modules\HR\Http\Requests\Concerns\ListsHr;

/** GET hr/checklists (HR-28): checklists of the employees at the reader's places; `?status=open|completed|all`, `?kind=`. */
class ListChecklistsRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new RosterShiftList;
    }

    protected function viewPermission(): string
    {
        return 'hr.checklist.view';
    }

    protected function statuses(): array
    {
        return ['open', 'completed'];
    }

    protected function extraRules(): array
    {
        return ['kind' => ['sometimes', 'string', 'in:onboarding,offboarding']];
    }
}
