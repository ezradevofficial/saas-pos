<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * AUTO-01..AUTO-03: POST automation-rules {name, document_type,
 * company_id?, trigger, conditions?, actions, webhook_secret?, enabled?}.
 * `core.automation.edit` at the company, or at tenant scope for a rule of
 * every company. Saved switched off unless `enabled` is true.
 */
class StoreRuleRequest extends FormRequest
{
    use ValidatesRuleDefinition;

    public function authorize(): bool
    {
        $access = app(AutomationAccess::class);
        $companyId = $this->input('company_id');
        $company = is_string($companyId) && Str::isUuid($companyId) ? Company::query()->find($companyId) : null;

        // An unknown or unreachable company is a validation error, never a confirmation it exists.
        if ($companyId !== null && ($company === null || ! $access->reachesCompany($this->user(), $company))) {
            return $access->anywhere($this->user());
        }

        return $access->mayEdit($this->user(), $company?->id);
    }

    public function rules(): array
    {
        return [
            ...$this->definitionRules(),
            'enabled' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkDefinition($v, $this->mergedDefinition(), is_string($this->input('webhook_secret')) && $this->input('webhook_secret') !== ''));
    }
}
