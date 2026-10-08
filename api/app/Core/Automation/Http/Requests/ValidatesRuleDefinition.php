<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\Actions\RuleContext;
use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Runtime\RuleValidator;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The body of a rule (AUTO-01..AUTO-03) on a Form Request: the field
 * rules, then RuleValidator on the whole definition (trigger, conditions,
 * actions, the author's permissions), its problems reported under their
 * path (`trigger`, `conditions`, `actions.0`, ...).
 */
trait ValidatesRuleDefinition
{
    /** @return array<string, list<mixed>> */
    protected function definitionRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'document_type' => [$required, 'string', Rule::in(app(DocumentTypeRegistry::class)->keys())],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id'), $this->reachableCompany()],
            'trigger' => [$required, 'array'],
            'conditions' => ['sometimes', 'nullable', 'array'],
            'actions' => [$required, 'array', 'max:'.RuleValidator::MAX_ACTIONS],
            'webhook_secret' => ['sometimes', 'nullable', 'string', 'min:16', 'max:200'],
        ];
    }

    protected function reachableCompany(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $company = is_string($value) ? Company::query()->find($value) : null;

            if ($value !== null && ($company === null || ! app(AutomationAccess::class)->reachesCompany($this->user(), $company))) {
                $fail(__('validation.exists', ['attribute' => __('automation.attributes.company_id')]));
            }
        };
    }

    /**
     * The definition to check: the body over $current (the saved rule's
     * values, for a partial update).
     *
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    protected function mergedDefinition(array $current = []): array
    {
        $data = $current;

        foreach (['name', 'document_type', 'company_id', 'trigger', 'conditions', 'actions'] as $key) {
            if ($this->has($key) || array_key_exists($key, $this->all())) {
                $data[$key] = $this->input($key);
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $definition */
    protected function checkDefinition(Validator $validator, array $definition, bool $hasSecret): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $type = app(DocumentTypeRegistry::class)->find((string) ($definition['document_type'] ?? ''));

        if ($type === null) {
            return;
        }

        $trigger = is_array($definition['trigger'] ?? null) ? $definition['trigger'] : [];
        $context = new RuleContext($type, Triggers::hasDocument($trigger), $definition['company_id'] ?? null, $this->user(), $hasSecret);

        foreach (app(RuleValidator::class)->validate($definition, $context) as $problem) {
            $validator->errors()->add($problem['path'], $problem['message']);
        }
    }

    public function attributes(): array
    {
        return [
            'name' => __('automation.attributes.name'),
            'document_type' => __('automation.attributes.document_type'),
            'company_id' => __('automation.attributes.company_id'),
            'trigger' => __('automation.attributes.trigger'),
            'conditions' => __('automation.attributes.conditions'),
            'actions' => __('automation.attributes.actions'),
            'webhook_secret' => __('automation.attributes.webhook_secret'),
        ];
    }
}
