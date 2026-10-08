<?php

namespace App\Core\Automation\Events;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;

/**
 * AUTO-01: what a module calls after it creates, changes or archives a
 * document of a registered type, so automation rules can react. The values
 * after the change are read through the type's own accessor
 * (fieldValues()); pass the values from before for an update or archive:
 *
 *     $before = $type->fieldValues($id);
 *     ... write ...
 *     $this->recordUpdated(MyType::KEY, $id, $before, $user);
 *
 * Nothing is raised for a type whose module the tenant has not activated.
 */
trait RaisesRecordChanges
{
    protected function recordCreated(string $documentType, string $documentId, ?User $by = null): void
    {
        $this->raiseRecordChange($documentType, $documentId, RecordChanged::CREATED, [], $by);
    }

    /** @param array<string, mixed> $before */
    protected function recordUpdated(string $documentType, string $documentId, array $before, ?User $by = null): void
    {
        $this->raiseRecordChange($documentType, $documentId, RecordChanged::UPDATED, $before, $by);
    }

    /** @param array<string, mixed> $before */
    protected function recordArchived(string $documentType, string $documentId, array $before, ?User $by = null): void
    {
        $this->raiseRecordChange($documentType, $documentId, RecordChanged::ARCHIVED, $before, $by);
    }

    /** @param array<string, mixed> $before */
    private function raiseRecordChange(string $documentType, string $documentId, string $change, array $before, ?User $by): void
    {
        $type = app(DocumentTypeRegistry::class)->find($documentType);

        if ($type === null) {
            return;
        }

        RecordChanged::dispatch(
            app(TenantContext::class)->require(),
            $documentType,
            $documentId,
            $change,
            $before,
            $type->fieldValues($documentId),
            $by?->id,
        );
    }
}
