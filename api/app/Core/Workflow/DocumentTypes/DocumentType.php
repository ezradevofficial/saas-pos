<?php

namespace App\Core\Workflow\DocumentTypes;

use App\Core\Currency\Money;
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
