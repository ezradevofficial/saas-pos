<?php

namespace App\Core\Workflow\Http\Requests;

use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\WorkflowAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * WF-02: POST workflows {document_type, company_id?}: a flow for a type in
 * a company (company_id null: every company without its own), starting
 * from the type's default as a draft. `core.workflow.edit` at the company,
 * or at tenant scope for every company.
 */
class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->input('company_id');
        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        // An unknown or unreachable company is a validation error, never a confirmation it exists.
        if ($companyId !== null && ($company === null || ! app(WorkflowAccess::class)->reachesCompany($this->user(), $company))) {
            return app(WorkflowAccess::class)->anywhere($this->user());
        }

        return app(WorkflowAccess::class)->may($this->user(), 'edit', $company?->id);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id'), function (string $attribute, mixed $value, Closure $fail) {
                $company = is_string($value) ? Company::query()->find($value) : null;

                if ($company === null || ! app(WorkflowAccess::class)->reachesCompany($this->user(), $company)) {
                    $fail(__('validation.exists', ['attribute' => __('workflow.attributes.company_id')]));
                }
            }],
        ];
    }

    public function attributes(): array
    {
        return [
            'document_type' => __('workflow.attributes.document_type'),
            'company_id' => __('workflow.attributes.company_id'),
        ];
    }
}
