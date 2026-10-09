<?php

namespace App\Core\CustomForms;

use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\CustomFields\CustomFieldDefinitions;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;

/**
 * WF-01, CF-04: one custom form type as a workflow document type,
 * `core.custom_form_<key>`, registered at run time for its tenant. Flows,
 * approvals, conditions and automation read its records through this class:
 * the number, place and amount, every header custom field (`cf_<key>`,
 * CustomFieldDocumentFields) and the total of each number or money line
 * field (`total_<key>`, CF-05).
 *
 * Its place is the record's company, branch and location; its requester
 * the record's creator (APR-07). The default flow (WF-02): one approval by
 * an Admin at the record's place, any one deciding; approved ends
 * `approved`, rejected ends `rejected`. Tenants change it in the builder.
 */
class CustomFormDocumentType extends DocumentType implements LinksDocuments
{
    public function __construct(private readonly CustomFormType $type) {}

    public function key(): string
    {
        return $this->type->documentType();
    }

    /** The type's name as the tenant typed it (__() returns it unchanged). */
    public function label(): string
    {
        return $this->type->name;
    }

    public function customFieldEntity(): ?string
    {
        return $this->type->entity();
    }

    public function fields(): array
    {
        $fields = [
            FieldDefinition::string('number', 'core.custom_form.fields.number'),
            FieldDefinition::money('amount', 'core.custom_form.fields.amount'),
            FieldDefinition::reference('company', 'core.custom_form.fields.company', 'core.company'),
            FieldDefinition::reference('branch', 'core.custom_form.fields.branch', 'core.branch'),
            FieldDefinition::reference('location', 'core.custom_form.fields.location', 'core.location'),
            FieldDefinition::reference('requested_by', 'core.custom_form.fields.requested_by', 'core.user'),
        ];

        foreach ($this->totalFields() as $field) {
            $fields[] = new FieldDefinition('total_'.$field->key, $field->type === 'money' ? 'money' : 'number', __('core.custom_form.fields.total', ['field' => $field->label]), literal: true);
        }

        return [...$fields, ...$this->customFields()];
    }

    public function fieldValues(string $documentId): array
    {
        $record = $this->find($documentId);

        if ($record === null) {
            return [];
        }

        $values = [
            'number' => $record->number,
            'amount' => $record->amount()?->jsonSerialize(),
            'company' => $record->company_id,
            'branch' => $record->branch_id,
            'location' => $record->location_id,
            'requested_by' => $record->created_by,
        ];

        foreach ($this->totalFields() as $field) {
            $values['total_'.$field->key] = $record->totals[$field->key] ?? null;
        }

        return [...$values, ...$this->customValues($record->custom)];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        return $this->find($documentId)?->documentScope();
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->created_by;
    }

    public function viewPermission(): string
    {
        return CustomFormAccess::VIEW;
    }

    public function actPermission(): string
    {
        return CustomFormAccess::APPROVE;
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function documentLink(string $documentId): string
    {
        return '/forms/'.$this->type->key.'/'.rawurlencode($documentId);
    }

    /** APR-04: the number, the type's name as the title and the record's amount. */
    public function summary(string $documentId): array
    {
        $record = $this->find($documentId);

        return [
            'number' => $record?->number,
            'title' => $record === null ? null : $this->type->name,
            'amount' => $record?->amount()?->jsonSerialize(),
        ];
    }

    public function amountLabel(): ?string
    {
        return 'core.custom_form.fields.amount';
    }

    public function defaultFlow(?string $country): ?array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __('core.custom_form.flow.start')],
                ['id' => 'approve', 'type' => 'approval', 'name' => __('core.custom_form.flow.approve'),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:admin'], 'mode' => 'any']],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __('core.custom_form.flow.approved')],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __('core.custom_form.flow.rejected')],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    /** @return list<\App\Core\CustomFields\CustomFieldDefinition> the line fields with a total (CF-05) */
    private function totalFields(): array
    {
        if (! $this->type->has_lines) {
            return [];
        }

        return app(CustomFieldDefinitions::class)->active($this->type->lineEntity())
            ->filter(fn ($field) => in_array($field->type, CustomForms::TOTALLED, true))->values()->all();
    }

    private function find(string $documentId): ?CustomFormRecord
    {
        return Str::isUuid($documentId) ? CustomFormRecord::query()->where('type_id', $this->type->id)->find($documentId) : null;
    }
}
