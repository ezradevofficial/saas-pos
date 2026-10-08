<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Illuminate\Validation\Validator;

/**
 * AUTO-04: POST automation-rules/test with a rule that is not saved (the
 * editor's current state) plus {values?, old_values?, document_id?}.
 * Checked as a new rule would be (StoreRuleRequest's permissions and
 * validation); nothing is saved or run.
 */
class TestUnsavedRuleRequest extends StoreRuleRequest
{
    use TestsRules;

    public function rules(): array
    {
        return [
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
}
