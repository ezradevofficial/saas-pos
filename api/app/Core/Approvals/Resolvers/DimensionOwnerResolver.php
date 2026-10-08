<?php

namespace App\Core\Approvals\Resolvers;

use App\Core\MasterData\Dimensions\Dimension;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;

/**
 * APR-02 `department_head` and `cost_centre_owner`: the owner of the
 * department (cost centre) the document names in a reference field to
 * `core.department` (`core.cost_centre`): the field named by the
 * approver's `field`, else the type's first such field. A type with no
 * such field cannot use this approver (the builder is told so).
 */
abstract class DimensionOwnerResolver implements ApproverResolver
{
    /** The reference target, e.g. `core.department`. */
    abstract protected function reference(): string;

    /** @return class-string<Dimension> */
    abstract protected function model(): string;

    public function params(): array
    {
        return [['name' => 'field', 'type' => 'field', 'reference' => $this->reference(), 'required' => false]];
    }

    public function validate(array $approver, DocumentType $type): array
    {
        if ((isset($approver['field']) && ! is_string($approver['field'])) || $this->field($approver, $type) === null) {
            return [__('approvals.validation.dimension_field', ['reference' => $this->reference()])];
        }

        return [];
    }

    public function resolve(array $approver, ApprovalSubject $subject): array
    {
        $field = $this->field($approver, $subject->type);
        $id = $field === null ? null : ($subject->values()[$field->name] ?? null);

        if (! is_string($id) || ! Str::isUuid($id)) {
            return [];
        }

        $owner = ($this->model())::query()->whereKey($id)->value('owner_user_id');

        return $owner === null ? [] : [(string) $owner];
    }

    public function describe(array $approver): string
    {
        return __($this->label());
    }

    private function field(array $approver, DocumentType $type): ?FieldDefinition
    {
        foreach ($type->fields() as $field) {
            if ($field->type === 'reference' && $field->reference === $this->reference()
                && (! isset($approver['field']) || $approver['field'] === $field->name)) {
                return $field;
            }
        }

        return null;
    }
}
