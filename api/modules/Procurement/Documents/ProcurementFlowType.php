<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\Money;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Procurement\Models\ProcurementDocument;

/**
 * WF-01: what Procurement's two flowed documents (requisitions, PRC-02;
 * purchase orders, PRC-13) share. Each is at a branch of a company (its
 * scope), has a requester (APR-07: never its approver), a number, custom
 * fields (CF-03) and the default flow WF-02 ships: one approval by a Branch
 * Manager at the branch (any one of them), escalated to the Admins after
 * two business days; approved ends `approved`, rejected ends `rejected`. A
 * tenant may copy and change it (route on the amount, the supplier, the
 * currency, WF-05). The engine reads documents only through these classes
 * (architecture rule 1).
 */
abstract class ProcurementFlowType extends DocumentType
{
    /** @return class-string<ProcurementDocument> */
    abstract protected function model(): string;

    /** The document's web page. */
    abstract protected function path(): string;

    /** `requisition` or `po`: the translation group of its flow's names. */
    abstract protected function flowKey(): string;

    /** @return array<string, mixed> the document's own field values */
    abstract protected function values(ProcurementDocument $document): array;

    /** The summary's amount. */
    abstract protected function amount(ProcurementDocument $document): ?Money;

    /** The summary's title (a supplier, a purpose). */
    abstract protected function title(ProcurementDocument $document): ?string;

    public function label(): string
    {
        return "procurement.workflow.{$this->flowKey()}.type";
    }

    public function fieldValues(string $documentId): array
    {
        $document = $this->find($documentId);

        if ($document === null) {
            return [];
        }

        return [
            'company' => $document->company_id,
            'branch' => $document->branch_id,
            ...$this->values($document),
            ...$this->customValues($document->custom ?? null),
        ];
    }

    /** @return list<FieldDefinition> */
    protected function placeFields(): array
    {
        return [
            FieldDefinition::reference('company', 'procurement.workflow.fields.company', 'core.company'),
            FieldDefinition::reference('branch', 'procurement.workflow.fields.branch', 'core.branch'),
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $document = $this->find($documentId);

        return $document === null ? null : new DocumentScope($document->company_id, $document->branch_id);
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

        return ['number' => $document->number, 'title' => $this->title($document), 'amount' => $this->amount($document)?->jsonSerialize()];
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'procurement.workflow.'.$this->flowKey();

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

    protected function find(string $documentId): ?ProcurementDocument
    {
        $model = $this->model();

        return Str::isUuid($documentId) ? $model::query()->find($documentId) : null;
    }

    protected static function money(mixed $minor, ?string $currency): ?Money
    {
        return $minor === null || $currency === null ? null : Money::ofMinor((string) $minor, $currency);
    }
}
