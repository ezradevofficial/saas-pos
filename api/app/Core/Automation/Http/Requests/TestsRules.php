<?php

namespace App\Core\Automation\Http\Requests;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\WorkflowAccess;
use Closure;
use Illuminate\Support\Str;

/**
 * AUTO-04 test mode bodies: `values` (sample field values), `old_values`
 * (the values before a change, for change triggers) or `document_id` (a
 * real document of the rule's type the user may see; its values are read
 * through the type). A document the user cannot see is "not found", the
 * same as one that does not exist.
 */
trait TestsRules
{
    private ?DocumentScope $documentScope = null;

    /** @return array<string, list<mixed>> */
    protected function testRules(): array
    {
        return [
            'values' => ['sometimes', 'array'],
            'old_values' => ['sometimes', 'nullable', 'array'],
            'document_id' => ['sometimes', 'nullable', 'uuid', $this->visibleDocument()],
        ];
    }

    abstract protected function testedType(): ?DocumentType;

    protected function visibleDocument(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $type = $this->testedType();
            $scope = $type !== null && is_string($value) && Str::isUuid($value) ? $type->scope($value) : null;

            if ($scope === null || ! app(WorkflowAccess::class)->seesDocument($this->user(), $type, $scope)) {
                $fail(__('automation.validation.document_not_found'));

                return;
            }

            $this->documentScope = $scope;
        };
    }

    public function documentId(): ?string
    {
        return $this->validated('document_id');
    }

    public function documentScope(): ?DocumentScope
    {
        return $this->documentId() === null ? null : $this->documentScope;
    }

    /** @return array<string, mixed> the document's values, else the sample's */
    public function testValues(): array
    {
        $id = $this->documentId();

        return $id !== null ? ($this->testedType()?->fieldValues($id) ?? []) : (array) $this->input('values', []);
    }

    /** @return array<string, mixed>|null */
    public function oldValues(): ?array
    {
        $old = $this->input('old_values');

        return is_array($old) ? $old : null;
    }
}
