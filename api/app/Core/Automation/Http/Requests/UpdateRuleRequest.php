<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Runtime\ActionList;
use App\Core\Tenancy\Models\Company;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * PATCH automation-rules/{rule}: any of name, document_type, company_id,
 * trigger, conditions, actions. Adding the first webhook generates the
 * signing secret, returned in this response only. A webhook action without
 * `url` (or with `keep_url: true`) keeps its stored URL (ActionList). The whole rule is checked again; moving it to another
 * company needs `core.automation.edit` there too. Each change raises the
 * version and is audited.
 */
class UpdateRuleRequest extends RuleRequest
{
    use ValidatesRuleDefinition;

    protected bool $edit = true;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        if (! array_key_exists('company_id', $this->all())) {
            return true;
        }

        $companyId = $this->input('company_id');

        if ($companyId === $this->rule()->company_id || ($companyId !== null && ! (is_string($companyId) && Str::isUuid($companyId)))) {
            return true;
        }

        $access = app(AutomationAccess::class);
        $company = $companyId === null ? null : Company::query()->find($companyId);

        // A company the user cannot reach is left to validation (never confirmed to exist).
        if ($companyId !== null && ($company === null || ! $access->reachesCompany($this->user(), $company))) {
            return true;
        }

        return $access->mayEdit($this->user(), $companyId);
    }

    /** Ids for new actions; webhook URLs left out (or `keep_url`) keep the stored one (ActionList). */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('actions'))) {
            $this->merge(['actions' => ActionList::merge($this->input('actions'), $this->rule()->actions ?? [])]);
        }
    }

    public function rules(): array
    {
        return $this->definitionRules(partial: true);
    }

    public function withValidator(Validator $validator): void
    {
        $rule = $this->rule();

        $validator->after(fn (Validator $v) => $this->checkDefinition($v, $this->mergedDefinition([
            'name' => $rule->name,
            'document_type' => $rule->document_type,
            'company_id' => $rule->company_id,
            ...$rule->definition(),
        ])));
    }
}
