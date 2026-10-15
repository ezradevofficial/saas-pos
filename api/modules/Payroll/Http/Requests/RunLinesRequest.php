<?php

namespace Modules\Payroll\Http\Requests;

use App\Core\Lists\Http\SortsAndExports;
use App\Core\Lists\ListDefinition;
use App\Core\Tenancy\Http\Requests\ListRequest;
use Modules\Payroll\Http\Lists\RunLineList;
use Modules\Payroll\Models\Run;

/**
 * GET payroll/runs/{payroll_run}/lines (PAY-14, PAY-15, PAY-25): the run's
 * lines for holders of `payroll.pay.view` at its company (decision 36: an
 * approver with `payroll.run.view` alone reads the run's totals, not each
 * employee's pay); `?errors=1` keeps lines with errors. Reading them is
 * audited (PAY-23).
 */
class RunLinesRequest extends PayrollRequest
{
    use SortsAndExports;

    public function list(): ListDefinition
    {
        return new RunLineList;
    }

    public function run(): Run
    {
        return $this->route('payroll_run');
    }

    public function authorize(): bool
    {
        return $this->onCompany($this->run()->company_id, 'payroll.pay.view', 'payroll.run.view');
    }

    public function rules(): array
    {
        return [
            ...$this->sortAndExportRules(),
            'search' => ['sometimes', 'string', 'max:100'],
            'errors' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,'.ListRequest::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', ListRequest::PER_PAGE);
    }
}
