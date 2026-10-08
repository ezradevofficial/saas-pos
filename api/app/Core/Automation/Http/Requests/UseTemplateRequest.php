<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\Templates\RuleTemplate;
use App\Core\Automation\Templates\RuleTemplates;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Closure;
use Illuminate\Validation\Validator;

/**
 * AUTO-07: POST automation-templates/use {template, document_type,
 * company_id?, name?, params?}: build the template's rule for the type and
 * save it switched off (StoreRuleRequest's permissions; the built rule is
 * validated like any other).
 */
class UseTemplateRequest extends StoreRuleRequest
{
    /** @var array<string, mixed>|null */
    private ?array $built = null;

    public function rules(): array
    {
        return [
            'template' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || app(RuleTemplates::class)->find($value) === null) {
                    $fail(__('automation.validation.unknown_template'));
                }
            }],
            'document_type' => $this->definitionRules()['document_type'],
            'company_id' => $this->definitionRules()['company_id'],
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'params' => ['sometimes', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $template = $this->template();
            $type = app(DocumentTypeRegistry::class)->get((string) $this->input('document_type'));

            if (! $template->appliesTo($type)) {
                $v->errors()->add('template', __('automation.validation.template_not_applicable'));

                return;
            }

            $this->checkDefinition($v, $this->definition(), false);
        });
    }

    public function template(): RuleTemplate
    {
        return app(RuleTemplates::class)->find((string) $this->input('template'));
    }

    /** @return array<string, mixed> the rule to save */
    public function definition(): array
    {
        if ($this->built !== null) {
            return $this->built;
        }

        $type = app(DocumentTypeRegistry::class)->get((string) $this->input('document_type'));
        $rule = $this->template()->build($type, (array) $this->input('params', []));
        $name = $this->input('name');

        return $this->built = [
            ...$rule,
            'name' => is_string($name) && $name !== '' ? $name : $rule['name'],
            'document_type' => $type->key(),
            'company_id' => $this->input('company_id'),
        ];
    }
}
