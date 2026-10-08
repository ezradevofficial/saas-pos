<?php

namespace Tests\Support\Workflow;

use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use App\Core\Workflow\DocumentTypes\NextDocument;

/**
 * A test-only document type (WF-01), shaped like a purchase requisition:
 * money, number, enum, string, date, boolean and reference fields, a draft
 * order it can create (WF-07), and default flows (any country, and one
 * for CD) (WF-02). Labels reuse existing translation keys.
 */
class TestRequestType extends DocumentType
{
    public const KEY = 'core.test_request';

    /** AUTO-01: set on an instance whose test raises RecordChanged for it as a module would. */
    public bool $raisesRecords = false;

    public function raisesRecordEvents(): bool
    {
        return $this->raisesRecords;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'workflow.list_title';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('total', 'workflow.columns.document_type'),
            FieldDefinition::money('budget', 'workflow.columns.company'),
            FieldDefinition::number('quantity', 'workflow.columns.published'),
            FieldDefinition::enum('category', 'workflow.columns.draft', ['goods', 'services', 'travel']),
            FieldDefinition::string('note', 'workflow.attributes.reason'),
            FieldDefinition::date('needed_by', 'workflow.columns.updated_at'),
            FieldDefinition::boolean('urgent', 'workflow.attributes.outcome'),
            FieldDefinition::reference('supplier', 'workflow.attributes.node', 'core.party'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        return TestDocuments::find(self::KEY, $documentId)['values'] ?? [];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        return TestDocuments::find(self::KEY, $documentId)['scope'] ?? null;
    }

    /** APR-07: the `requested_by` value, as a module's creator column. */
    public function requesterId(string $documentId): ?string
    {
        return TestDocuments::find($this->key(), $documentId)['values']['requested_by'] ?? null;
    }

    public function viewPermission(): string
    {
        return 'core.party.view';
    }

    public function actPermission(): string
    {
        return 'core.party.edit';
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function nextDocuments(): array
    {
        return [
            new NextDocument('order', TestOrderType::KEY, 'workflow.columns.draft', ['amount' => 'total', 'supplier' => 'supplier']),
            // A target in an optional module (ExtOrderType), registered by tests that switch it off.
            new NextDocument('ext_order', ExtOrderType::KEY, 'workflow.columns.draft', ['amount' => 'total']),
        ];
    }

    public function defaultFlow(?string $country): ?array
    {
        if ($country === 'CD') {
            return [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'review', 'type' => 'stage', 'name' => 'Review'],
                    ['id' => 'finance', 'type' => 'stage', 'name' => 'Finance check'],
                    ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'finance'],
                    ['from' => 'finance', 'to' => 'end'],
                ],
            ];
        }

        if ($country === null || $country === 'KE') {
            return [
                'nodes' => [
                    ['id' => 'start', 'type' => 'start'],
                    ['id' => 'review', 'type' => 'stage', 'name' => 'Review', 'exit_roles' => ['template:admin', 'template:owner']],
                    ['id' => 'end', 'type' => 'end', 'outcome' => 'approved'],
                ],
                'edges' => [
                    ['from' => 'start', 'to' => 'review'],
                    ['from' => 'review', 'to' => 'end'],
                ],
            ];
        }

        return null;
    }
}
