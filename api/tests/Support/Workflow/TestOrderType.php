<?php

namespace Tests\Support\Workflow;

use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;

/** A test-only target type for WF-07 (createDraft) and WF-11 (cancelDocument). */
class TestOrderType extends DocumentType
{
    public const KEY = 'core.test_order';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'workflow.columns.draft';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('amount', 'workflow.columns.document_type'),
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

    public function viewPermission(): string
    {
        return 'core.party.view';
    }

    public function actPermission(): string
    {
        return 'core.party.edit';
    }

    public function createDraft(array $values, DocumentScope $scope, ?User $by): string
    {
        return TestDocuments::create(self::KEY, $values, $scope);
    }

    public function isCancelled(string $documentId): bool
    {
        return (TestDocuments::find(self::KEY, $documentId)['status'] ?? 'cancelled') === 'cancelled';
    }

    public function cancelDocument(string $documentId, string $reason, ?User $by): void
    {
        TestDocuments::setStatus($documentId, 'cancelled');
    }
}
