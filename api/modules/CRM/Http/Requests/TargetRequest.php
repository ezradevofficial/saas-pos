<?php

namespace Modules\CRM\Http\Requests;

use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Modules\CRM\Models\Target;
use Modules\CRM\Support\CrmAccess;
use Modules\CRM\Targets\Targets;

/**
 * CRM-30: POST crm/targets `{company_id, branch_id?, subject_type:
 * user|team|branch, subject_id, metric, period_start, period_end,
 * amount_minor}` with `crm.target.manage` at the branch (or the company
 * for a company-wide target); PATCH crm/targets/{id} the same fields but
 * the company, and POST …/archive, on a target the user sees (404
 * otherwise) and manages (403).
 */
class TargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(CrmAccess::class);
        $target = $this->target();

        if ($target !== null) {
            abort_unless(app(Targets::class)->sees($this->user(), $target), 404);

            return app(Targets::class)->mayManage($this->user(), $target);
        }

        $company = is_string($this->input('company_id')) && Str::isUuid($this->input('company_id')) ? Company::query()->find($this->input('company_id')) : null;

        if ($company === null) {
            return $access->anywhere($this->user(), 'crm.target.manage');
        }

        $branch = $this->input('subject_type') === 'branch' ? $this->input('subject_id') : $this->input('branch_id');

        return is_string($branch) && Str::isUuid($branch)
            ? $access->canAt($this->user(), 'crm.target.manage', $branch) || $access->canAtCompany($this->user(), 'crm.target.manage', $company->id)
            : $access->canAtCompany($this->user(), 'crm.target.manage', $company->id);
    }

    public function rules(): array
    {
        if ($this->route()?->getActionMethod() === 'archive') {
            return [];
        }

        $creating = $this->target() === null;
        $required = $creating ? 'required' : 'sometimes';

        return [
            ...($creating ? ['company_id' => CrmRules::id('companies', required: true)] : []),
            'branch_id' => CrmRules::id('branches'),
            'subject_type' => [$required, 'string', Rule::in(Target::SUBJECTS)],
            'subject_id' => [$required, 'uuid'],
            'metric' => [$required, 'string', Rule::in(Target::METRICS)],
            'period_start' => [$required, 'date_format:Y-m-d'],
            'period_end' => [$required, 'date_format:Y-m-d'],
            'amount_minor' => [$required, 'string', CrmRules::MINOR],
        ];
    }

    public function target(): ?Target
    {
        $target = $this->route('crm_target');

        return $target instanceof Target ? $target : null;
    }
}
