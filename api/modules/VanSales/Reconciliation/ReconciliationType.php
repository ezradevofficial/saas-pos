<?php

namespace Modules\VanSales\Reconciliation;

use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Illuminate\Support\Str;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Van;

/**
 * WF-01, VAN-21: `van_sales.reconciliation`, a van's end-of-day count with a
 * variance or damaged stock, posted when its flow approves it. The default
 * flow is one approval by a Route supervisor where the van is (any one,
 * APR-07: never the rep who counted), escalated to the Branch managers after
 * two business days; a tenant may copy and change it. Conditions may route
 * on `cash_variance` (the absolute cash and mobile money variance, in the
 * company's base currency) and `stock_variance_lines`.
 */
class ReconciliationType extends DocumentType
{
    public const KEY = 'van_sales.reconciliation';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'van_sales.workflow.reconciliation.type';
    }

    public function fields(): array
    {
        return [
            FieldDefinition::reference('location', 'van_sales.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('branch', 'van_sales.workflow.fields.branch', 'core.branch'),
            FieldDefinition::reference('company', 'van_sales.workflow.fields.company', 'core.company'),
            FieldDefinition::reference('rep', 'van_sales.workflow.fields.rep', 'core.user'),
            FieldDefinition::money('cash_variance', 'van_sales.workflow.fields.cash_variance'),
            FieldDefinition::number('stock_variance_lines', 'van_sales.workflow.fields.stock_variance_lines'),
        ];
    }

    public function fieldValues(string $documentId): array
    {
        $count = $this->find($documentId);

        if ($count === null) {
            return [];
        }

        $van = Van::query()->find($count->van_id);

        return [
            'location' => $van?->location_id,
            'branch' => $count->branch_id,
            'company' => $count->company_id,
            'rep' => $count->submitted_by,
            'cash_variance' => ['amount_minor' => (string) $count->cash_variance_base_minor, 'currency' => (string) (Company::query()->whereKey($count->company_id)->value('base_currency') ?? 'KES')],
            'stock_variance_lines' => (int) $count->stock_variance_lines,
        ];
    }

    public function scope(string $documentId): ?DocumentScope
    {
        $count = $this->find($documentId);
        $location = $count === null ? null : Van::query()->whereKey($count->van_id)->value('location_id');

        return $count === null ? null : new DocumentScope($count->company_id, $count->branch_id, $location);
    }

    public function requesterId(string $documentId): ?string
    {
        return $this->find($documentId)?->submitted_by;
    }

    public function viewPermission(): string
    {
        return 'van_sales.reconciliation.view';
    }

    public function actPermission(): string
    {
        return 'van_sales.reconciliation.approve';
    }

    public function documentLink(string $documentId): string
    {
        return '/van-sales/reconciliations/'.rawurlencode($documentId);
    }

    public function actions(): array
    {
        return ['submit', 'approve', 'reject', 'cancel'];
    }

    public function summary(string $documentId): array
    {
        $count = $this->find($documentId);

        if ($count === null) {
            return ['number' => null, 'title' => null, 'amount' => null];
        }

        $location = Van::query()->whereKey($count->van_id)->value('name');

        return ['number' => null, 'title' => $location, 'amount' => null];
    }

    public function defaultFlow(?string $country): ?array
    {
        $prefix = 'van_sales.workflow.reconciliation';

        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start', 'name' => __("{$prefix}.start")],
                ['id' => 'approve', 'type' => 'approval', 'name' => __("{$prefix}.approve"),
                    'approval' => ['approver' => ['type' => 'role', 'role' => 'template:route_supervisor'], 'mode' => 'any'],
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

    private function find(string $documentId): ?Count
    {
        return Str::isUuid($documentId) ? Count::query()->find($documentId) : null;
    }
}
