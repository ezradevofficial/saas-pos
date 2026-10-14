<?php

namespace Modules\HR\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Modules\HR\Http\Lists\AppraisalList;
use Modules\HR\Http\Requests\Concerns\ListsHr;
use Modules\HR\Models\Appraisal;

/** GET hr/appraisals (HR-30). */
class ListAppraisalsRequest extends FormRequest
{
    use ListsHr;

    public function list(): ListDefinition
    {
        return new AppraisalList;
    }

    protected function viewPermission(): string
    {
        return 'hr.appraisal.view';
    }

    protected function statuses(): array
    {
        return Appraisal::STATUSES;
    }
}
