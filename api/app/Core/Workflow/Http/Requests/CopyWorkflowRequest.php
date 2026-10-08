<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\WorkflowAccess;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Spec 6.4: POST workflows/{workflow}/copy {company_id, from?}: this flow's
 * published graph (`from: draft` for its draft) becomes the draft of the
 * same type's flow in another company (company_id null: every company).
 * Reading this flow, and `core.workflow.edit` where the copy goes.
 */
class CopyWorkflowRequest extends WorkflowRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['present', 'nullable', 'uuid', Rule::exists('companies', 'id'), function (string $attribute, mixed $value, Closure $fail) {
                $company = is_string($value) ? Company::query()->find($value) : null;

                if ($company === null || ! app(WorkflowAccess::class)->reachesCompany($this->user(), $company)) {
                    $fail(__('validation.exists', ['attribute' => __('workflow.attributes.company_id')]));
                }
            }],
            'from' => ['sometimes', 'string', 'in:published,draft'],
        ];
    }

    public function attributes(): array
    {
        return ['company_id' => __('workflow.attributes.company_id'), 'from' => __('workflow.attributes.from')];
    }

    public function target(): ?Company
    {
        $id = $this->validated('company_id');

        return $id === null ? null : Company::query()->findOrFail($id);
    }

    /** Called after validation: the copy needs edit rights where it goes. */
    public function authorizeTarget(): void
    {
        abort_unless(app(WorkflowAccess::class)->may($this->user(), 'edit', $this->validated('company_id')), 403);
    }
}
