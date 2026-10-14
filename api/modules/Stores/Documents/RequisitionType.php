<?php

namespace Modules\Stores\Documents;

use App\Core\Tenancy\Models\Location;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\Stores\Models\Requisition;

/**
 * WF-01, STR-03: `stores.requisition`, a department's request to a store.
 * Its scope is the store (company, branch, location); its requester never
 * approves it (APR-07). The default flow (WF-02) is one approval by the
 * department head (APR-02 `department_head`), escalated to the store's
 * Branch Managers after two business days, and going to them at once when
 * the department has no head. Conditions may route on the store, the
 * department, the cost centre and the number of lines. The engine reads
 * requisitions only through this class (architecture rule 1).
 */
class RequisitionType extends DocumentType
{
    public const KEY = 'stores.requisition';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'stores.workflow.requisition.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('department', 'stores.workflow.fields.department', 'core.department'),
            FieldDefinition::reference('cost_centre', 'stores.workflow.fields.cost_centre', 'core.cost_centre'),
            FieldDefinition::reference('location', 'stores.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'stores.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'stores.workflow.fields.company', 'core.company'),
            FieldDefinition::number('lines', 'stores.workflow.fields.lines'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $requisition = $this->find($documentId);

        if ($requisition === null) {
            return [];
        }

        return [
            'department' => $requisition->department_id,
            'cost_centre' => $requisition->cost_centre_id,
            'location' => $requisition->location_id,
            'branch' => $requisition->branch_id,
            'company' => $requisition->company_id,
            'lines' => $requisition->lines()->count(),
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $requisition = $this->find($documentId);

        return $requisition === null ? null : new DocumentScope($requisition->company_id, $requisition->branch_id, $requisition->location_id);
    }

    public function viewPermission(): string
    {
        return 'stores.requisition.view';
    }

    public function actPermission(): string
    {
        return 'stores.requisition.approve';
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->requested_by;
    }

    public function documentLink(string $documentId): string
    {
        return '/stores/requisitions/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    /** APR-04: the number and the store; a requisition carries no amount. */
    public function summary(string $documentId): array
    {
        $requisition = $this->find($documentId);

        if ($requisition === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        return ['number' => $requisition->number, 'title' => Location::query()->whereKey($requisition->location_id)->value('name'), 'amount' => null];
    }

    public function amountLabel(): ?string
    {
        return null;
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'stores.workflow.requisition';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'department_head', 'field' => 'department'], 'mode' => 'any'],
                    'escalation' => ['after' => ['amount' => 2, 'unit' => 'business_days'], 'to' => ['type' => 'role', 'role' => 'template:branch_manager']]],
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

    private function find(string $documentId): ?Requisition
    {
        return Str::isUuid($documentId) ? Requisition::query()->find($documentId) : null;
    }
}
