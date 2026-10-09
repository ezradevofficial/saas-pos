<?php

namespace App\Core\Workflow\DocumentTypes;

use App\Core\Currency\Money;
use App\Core\CustomFields\CustomFieldDocumentFields;
use App\Core\Identity\Models\User;
use App\Core\Workflow\Definitions\FlowGraph;
use LogicException;

/**
 * A kind of document a module runs through flows (WF-01), registered in the
 * module's service provider:
 *
 *   app(DocumentTypeRegistry::class)->register(CreditLimitChangeType::class);
 *
 * The engine never reads a module's tables (architecture rule 1): it asks
 * the type for a document's field values and scope, and asks target types
 * to create (WF-07) or cancel (WF-11) documents. Every accessor runs inside
 * the tenant's context.
 */
abstract class DocumentType
{
    /** `{module}.{document}`, e.g. `core.credit_limit_change`. */
    abstract public function key(): string;

    /** Translation key of the type's name. */
    abstract public function label(): string;

    /** @return list<FieldDefinition> the fields conditions and mappings may read */
    abstract public function fields(): array;

    /**
     * The document's current values by field name (see FieldDefinition for
     * the value shapes). Fields the document lacks may be left out (null).
     *
     * @return array<string, mixed>
     */
    abstract public function fieldValues(string $documentId): array;

    /**
     * Where the document belongs (permissions, approvers, calendar), or null
     * when the current tenant has no such document.
     */
    abstract public function scope(string $documentId): ?DocumentScope;

    /** The module's permission to see a document's flow status and history (WF-10). */
    abstract public function viewPermission(): string;

    /**
     * The permission to move a document through a stage that names no roles
     * (WF-08), checked at the document's scope.
     */
    abstract public function actPermission(): string;

    /**
     * AUTO-03, AUTO-04: what a person reads for the document's reference
     * fields (a party's name instead of its id), for notification
     * placeholders, test mode and webhook `<field>_label`s.
     *
     * @return array<string, string> field name => display value
     */
    public function displayValues(string $documentId, ?User $viewer = null): array
    {
        return $this->displayValuesOf($this->fieldValues($documentId), $viewer);
    }

    /**
     * displayValues() from values already read (or a test's sample). By
     * default the names of the platform's own reference targets
     * (core.party, core.company, core.branch, core.location, core.user,
     * see ReferenceLabels), empty when $viewer's field rules hide the name
     * (RBAC-05). A type with references to its module's records overrides
     * this and adds its own; fields left out show as given.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function displayValuesOf(array $values, ?User $viewer = null): array
    {
        return app(ReferenceLabels::class)->of($this->fields(), $values, $viewer);
    }

    /**
     * AUTO-01: whether the module raises RecordChanged for this type's
     * documents (RaisesRecordChanges) when they are created, changed or
     * archived. Only then may rules use the record triggers
     * (record_created, record_updated, record_archived, field_changed,
     * threshold); a type that never raises them would never fire one.
     */
    public function raisesRecordEvents(): bool
    {
        return false;
    }

    /** @return list<string> actions the module allows on the document (WF-01), e.g. submit, approve, cancel */
    public function actions(): array
    {
        return [];
    }

    /**
     * The flow the type ships (WF-02) as a graph (see FlowGraph), for a
     * country pack (`KE`, `CD`) or null for any country. Returning null
     * means the type ships no default.
     *
     * @return array<string, mixed>|null
     */
    public function defaultFlow(?string $country): ?array
    {
        return null;
    }

    /** @return list<NextDocument> documents a flow of this type may create (WF-07) */
    public function nextDocuments(): array
    {
        return [];
    }

    /**
     * WF-07: create a draft of this type from mapped values, in the source
     * document's scope; returns the new document's id. Only types that are
     * the target of a NextDocument implement it.
     *
     * @param  array<string, mixed>  $values
     */
    public function createDraft(array $values, DocumentScope $scope, ?User $by): string
    {
        throw new LogicException("The document type [{$this->key()}] cannot create drafts.");
    }

    /**
     * WF-07: whether a document a flow created is cancelled (or gone), so a
     * flow passing the same action again after a return creates a new one
     * instead of keeping it. By default: gone when it has no scope.
     */
    public function isCancelled(string $documentId): bool
    {
        return $this->scope($documentId) === null;
    }

    /** WF-11: cancel a document a cancelled flow had created (on_cancel: cancel). */
    public function cancelDocument(string $documentId, string $reason, ?User $by): void
    {
        throw new LogicException("The document type [{$this->key()}] cannot cancel documents.");
    }

    /**
     * APR-04: what the approvals inbox shows of a document: its number
     * (e.g. `PR-0042`), a one-line title and its amount. By default no
     * number or title, and the value of the type's first money field.
     *
     * @return array{number: ?string, title: ?string, amount: ?array{amount_minor: string, currency: string}}
     */
    /**
     * APR-04: the translation key naming the summary's amount in the
     * approvals inbox (e.g. "New credit limit"). By default the label of the
     * type's first money field, the one summary() reads.
     */
    public function amountLabel(): ?string
    {
        foreach ($this->fields() as $field) {
            if ($field->type === 'money') {
                return $field->label;
            }
        }

        return null;
    }

    public function summary(string $documentId): array
    {
        $amount = null;

        foreach ($this->fields() as $field) {
            if ($field->type === 'money') {
                $value = $this->fieldValues($documentId)[$field->name] ?? null;

                if ($value instanceof Money) {
                    $value = $value->jsonSerialize();
                }

                if (is_array($value) && isset($value['amount_minor'], $value['currency'])) {
                    $amount = ['amount_minor' => (string) $value['amount_minor'], 'currency' => (string) $value['currency']];
                }

                break;
            }
        }

        return ['number' => null, 'title' => null, 'amount' => $amount];
    }

    /**
     * WF-02: the type's own rules on a flow, checked with the graph before
     * a version is saved as valid or published (after the structural
     * checks, on an acyclic graph). Problems as GraphValidator reports them.
     *
     * @return list<array{code: string, message: string, node: ?string}>
     */
    public function validateFlow(FlowGraph $flow): array
    {
        return [];
    }

    /**
     * APR-04, RBAC-05: parts of summary() hidden from $viewer, among
     * `title` and `amount` (e.g. the amount when their field rules hide it).
     * The approvals inbox, its search and export, its notices and emails
     * and the approve-by-email page leave them out for that viewer.
     *
     * @return list<string>
     */
    public function hiddenSummaryFields(User $viewer): array
    {
        return [];
    }

    /**
     * APR-07: the user who requested the document (its creator), so they
     * never approve it, besides whoever started its flow. Part of the
     * contract for types used with approval nodes: a flow with an approval
     * node cannot be published for a type that does not override this
     * (ApprovalConfig), and a flow started by the system (no user) records
     * this user as the requester.
     */
    public function requesterId(string $documentId): ?string
    {
        return null;
    }

    /** Whether the type implements requesterId() (L6). */
    final public function knowsRequester(): bool
    {
        return (new \ReflectionMethod($this, 'requesterId'))->getDeclaringClass()->getName() !== self::class;
    }

    /**
     * CF-03: the custom field entity of this type's documents
     * (CustomFieldEntities), or null. A type that names one adds
     * customFields() to fields() and customValues() to fieldValues(), so
     * conditions, automation and placeholders can use them.
     */
    public function customFieldEntity(): ?string
    {
        return null;
    }

    /** @return list<FieldDefinition> the entity's custom fields, named `cf_<key>` (CustomFieldDocumentFields) */
    protected function customFields(): array
    {
        $entity = $this->customFieldEntity();

        return $entity === null ? [] : app(CustomFieldDocumentFields::class)->fields($entity);
    }

    /**
     * @param  array<string, mixed>|null  $custom  the document's stored custom values
     * @return array<string, mixed> `cf_<key>` => value
     */
    protected function customValues(?array $custom): array
    {
        $entity = $this->customFieldEntity();

        return $entity === null ? [] : app(CustomFieldDocumentFields::class)->values($entity, $custom);
    }

    public function field(string $name): ?FieldDefinition
    {
        foreach ($this->fields() as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, FieldDefinition> */
    public function fieldsByName(): array
    {
        $fields = [];

        foreach ($this->fields() as $field) {
            $fields[$field->name] = $field;
        }

        return $fields;
    }

    public function nextDocument(string $key): ?NextDocument
    {
        foreach ($this->nextDocuments() as $next) {
            if ($next->key === $key) {
                return $next;
            }
        }

        return null;
    }
}
