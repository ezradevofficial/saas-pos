<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Lists\ListDefinition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Payroll\Http\Lists\RunList;
use Modules\Payroll\Http\Requests\Concerns\ListsPayroll;
use Modules\Payroll\Models\Run;

/** GET payroll/runs (PAY-14, PAY-25): runs of the companies where the reader holds `payroll.run.view`, `?pay_group=`, `?kind=`. */
class ListRunsRequest extends FormRequest
{
    use ListsPayroll;

    public function list(): ListDefinition
    {
        return new RunList;
    }

    protected function viewPermission(): string
    {
        return 'payroll.run.view';
    }

    protected function statuses(): array
    {
        return [Run::DRAFT, Run::CALCULATED, Run::PENDING, Run::APPROVED, Run::POSTED, Run::CANCELLED];
    }

    protected function extraRules(): array
    {
        return [
            'pay_group' => ['sometimes', 'uuid', Rule::exists('payroll_pay_groups', 'id')],
            'kind' => ['sometimes', 'string', Rule::in([Run::REGULAR, Run::CORRECTION])],
        ];
    }
}
