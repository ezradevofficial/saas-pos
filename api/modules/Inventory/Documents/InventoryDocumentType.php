<?php

namespace Modules\Inventory\Documents;

use App\Core\Currency\Money;
use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Tenancy\Models\Location;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * WF-01: what Inventory's three flowed documents (transfers, adjustments,
 * counts) share. Each is at a location of a branch of a company (its
 * scope), has a requester (APR-07: never its approver), a number, and the
 * default flow WF-02 ships: one approval by a Branch Manager where the
 * document is (any one of them), escalated to the Admins after two
 * business days; approved ends `approved`, rejected ends `rejected`. A
 * tenant may copy and change it, a flow with no approval included. The
 * engine reads documents only through these classes (architecture rule 1).
 * Values hidden from a viewer without `inventory.valuation.view` (RBAC-05)
 * are left out of the approvals inbox.
 */
abstract class InventoryDocumentType extends DocumentType
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** The location the document's scope and summary title follow. */
    abstract protected function locationOf(Model $document): string;

    /** The document's web page. */
    abstract protected function path(): string;

    public function scope(string $documentId): ?DocumentScope
    {
        $document = $this->find($documentId);

        return $document === null ? null : new DocumentScope($document->company_id, $document->branch_id, $this->locationOf($document));
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function documentLink(string $documentId): string
    {
        return $this->path().'/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $document = $this->find($documentId);

        if ($document === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return [
            'number' => $document->number,
            'title' => Location::query()->whereKey($this->locationOf($document))->value('name'),
            'amount' => $this->amount($document)?->jsonSerialize(),
        ];
    }

    /** RBAC-05: values only for holders of `inventory.valuation.view`. */
    public function hiddenSummaryFields(User $viewer): array
    {
        return app(ScopeResolver::class)->can($viewer, 'inventory.valuation.view') ? [] : ['amount'];
    }

    /** The summary's amount: the document's value at the company's cost. */
    protected function amount(Model $document): ?Money
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'inventory.workflow.'.$this->flowKey();

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:branch_manager'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:admin']]],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved', 'name' => __("{$prefix}.approved")],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected', 'name' => __("{$prefix}.rejected")],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    /** `transfer`, `adjustment` or `count`: the translation group of its flow's stage names. */
    abstract protected function flowKey(): string;

    protected function find(string $documentId): ?Model
    {
        $model = $this->model();

        return Str::isUuid($documentId) ? $model::query()->find($documentId) : null;
    }

    protected static function money(mixed $minor, ?string $currency): ?Money
    {
        return $minor === null || $currency === null ? null : Money::ofMinor((string) $minor, $currency);
    }
}
