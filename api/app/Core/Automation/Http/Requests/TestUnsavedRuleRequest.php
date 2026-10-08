<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Automation\AutomationAccess;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Runtime\ActionList;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * AUTO-04: POST automation-rules/test with a rule that is not saved (the
 * editor's current state) plus {values?, old_values?, document_id?}.
 * Checked as a new rule would be (StoreRuleRequest's permissions and
 * validation); nothing is saved or run. `rule_id` names the saved rule
 * being edited, so webhook actions sent without `url` (they are
 * write-only) take the stored address, as a save would; it must be a rule
 * the user sees, else it is refused like an unknown id.
 */
class TestUnsavedRuleRequest extends StoreRuleRequest
{
    use TestsRules;

    protected function prepareForValidation(): void
    {
        // Ids for new actions as on save; with the rule being edited, its stored webhook URLs too.
        if (is_array($this->input('actions'))) {
            $this->merge(['actions' => ActionList::merge($this->input('actions'), $this->editedRule()?->actions ?? [])]);
        }
    }

    public function rules(): array
    {
        return [
            'rule_id' => ['sometimes', 'nullable', 'uuid', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->editedRule() === null) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
            ...$this->definitionRules(),
            // A rule being drafted may have no name yet.
            'name' => ['sometimes', 'nullable', 'string', 'max:120'],
            ...$this->testRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $v) => $this->checkDefinition($v, $this->mergedDefinition()));
    }

    protected function testedType(): ?DocumentType
    {
        $key = $this->input('document_type');

        return is_string($key) ? app(DocumentTypeRegistry::class)->find($key) : null;
    }

    /** The saved rule being edited, if the user sees it (RLS keeps other tenants out). */
    private function editedRule(): ?AutomationRule
    {
        $id = $this->input('rule_id');

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        $rule = AutomationRule::query()->find($id);

        return $rule !== null && app(AutomationAccess::class)->sees($this->user(), $rule) ? $rule : null;
    }
}
