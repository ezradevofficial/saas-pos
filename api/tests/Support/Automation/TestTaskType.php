<?php

namespace Tests\Support\Automation;

use App\Core\Automation\Capabilities\AssignsUsers;
use App\Core\Automation\Capabilities\FindsDocumentsByDate;
use App\Core\Automation\Capabilities\HoldsCredit;
use App\Core\Automation\Capabilities\LinksDocuments;
use App\Core\Automation\Capabilities\UpdatesFields;
use App\Core\Automation\Events\RaisesRecordChanges;
use App\Core\Identity\Models\User;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Carbon\CarbonImmutable;
use Tests\Support\Workflow\TestDocuments;

/**
 * A test-only document type with every automation capability (AUTO-01,
 * AUTO-03): writable fields, an assignable person field, credit hold, a
 * date it can be searched by and a link. Its writes raise RecordChanged
 * like a module's would (RaisesRecordChanges). Documents live in the
 * in-memory TestDocuments, tenant-bound. `failNext` makes the next
 * updateField() throw, to test retries.
 */
class TestTaskType extends DocumentType implements AssignsUsers, FindsDocumentsByDate, HoldsCredit, LinksDocuments, UpdatesFields
{
    use RaisesRecordChanges;

    public const KEY = 'core.test_task';

    /** @var int how many of the next updateField() calls throw a RuntimeException */
    public static int $failNext = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'automation.columns.document';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::string('title', 'automation.columns.name'),
            FieldDefinition::enum('status', 'automation.columns.status', ['open', 'approved', 'closed']),
            FieldDefinition::money('amount', 'automation.columns.trigger'),
            FieldDefinition::number('quantity', 'automation.columns.attempts'),
            FieldDefinition::date('due_on', 'automation.columns.updated_at'),
            FieldDefinition::boolean('urgent', 'automation.columns.outcome'),
            FieldDefinition::reference('owner', 'automation.columns.rule', 'core.user'),
            FieldDefinition::boolean('on_hold', 'automation.columns.error'),
            FieldDefinition::string('note', 'automation.columns.version'),
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

    public function writableFields(): array
    {
        return ['status', 'urgent', 'quantity', 'note'];
    }

    public function updateField(string $documentId, string $field, mixed $value, ?User $by): void
    {
        if (self::$failNext > 0) {
            self::$failNext--;

            throw new \RuntimeException('The task store is down.');
        }

        $this->write($documentId, [$field => $value], $by);
    }

    public function assignableFields(): array
    {
        return ['owner'];
    }

    public function assignUser(string $documentId, string $field, string $userId, ?User $by): void
    {
        $this->write($documentId, [$field => $userId], $by);
    }

    public function setCreditHold(string $documentId, bool $hold, string $reason, ?User $by): void
    {
        $this->write($documentId, ['on_hold' => $hold, 'note' => $reason], $by);
    }

    public function documentsOnDate(string $field, string $date, string $companyId, string $timezone): array
    {
        $ids = [];

        foreach (TestDocuments::ofType(self::KEY) as $document) {
            $value = $document['values'][$field] ?? null;
            $scope = $this->scope($document['id']);

            if (! is_string($value) || $scope?->companyId !== $companyId) {
                continue;
            }

            $day = strlen($value) === 10 ? $value : CarbonImmutable::parse($value)->setTimezone($timezone)->toDateString();

            if ($day === $date) {
                $ids[] = $document['id'];
            }
        }

        return $ids;
    }

    public function documentLink(string $documentId): string
    {
        return '/tasks/'.$documentId;
    }

    public function createDraft(array $values, DocumentScope $scope, ?User $by): string
    {
        return $this->create($values, $scope, $by);
    }

    // ---- what a module's own code would do -----------------------------------

    public function create(array $values, DocumentScope $scope, ?User $by = null): string
    {
        $id = TestDocuments::create(self::KEY, $values, $scope);
        $this->recordCreated(self::KEY, $id, $by);

        return $id;
    }

    public function write(string $documentId, array $values, ?User $by = null): void
    {
        $before = $this->fieldValues($documentId);
        TestDocuments::update($documentId, $values);
        $this->recordUpdated(self::KEY, $documentId, $before, $by);
    }

    public function archive(string $documentId, ?User $by = null): void
    {
        $before = $this->fieldValues($documentId);
        TestDocuments::setStatus($documentId, 'archived');
        $this->recordArchived(self::KEY, $documentId, $before, $by);
    }
}
