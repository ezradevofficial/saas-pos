<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\Money;
use App\Core\Tenancy\Models\Branch;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Modules\Procurement\CustomFields\RequisitionEntity;
use Modules\Procurement\Models\ProcurementDocument;
use Modules\Procurement\Models\Requisition;

/**
 * WF-01, PRC-02: `procurement.requisition`, approved through its flow.
 * Conditions may route on the estimated total, the department, the branch
 * and custom fields (e.g. the CFO above an amount).
 */
class RequisitionType extends ProcurementFlowType
{
    public const KEY = 'procurement.requisition';

    public function key(): string
    {
        return self::KEY;
    }

    public function fields(): array
    {
        return [
            ...$this->placeFields(),
            FieldDefinition::reference('location', 'procurement.workflow.fields.location', 'core.location'),
            FieldDefinition::money('estimated_total', 'procurement.workflow.fields.estimated_total'),
            FieldDefinition::number('lines', 'procurement.workflow.fields.lines'),
            ...$this->customFields(),
        ];
    }

    public function customFieldEntity(): ?string
    {
        return RequisitionEntity::KEY;
    }

    public function viewPermission(): string
    {
        return 'procurement.requisition.view';
    }

    public function actPermission(): string
    {
        return 'procurement.requisition.approve';
    }

    protected function model(): string
    {
        return Requisition::class;
    }

    protected function path(): string
    {
        return '/procurement/requisitions';
    }

    protected function flowKey(): string
    {
        return 'requisition';
    }

    protected function values(ProcurementDocument $document): array
    {
        return [
            'location' => $document->location_id,
            'estimated_total' => self::money($document->estimated_total_minor, $document->currency),
            'lines' => $document->lines()->count(),
        ];
    }

    protected function amount(ProcurementDocument $document): ?Money
    {
        return self::money($document->estimated_total_minor, $document->currency);
    }

    protected function title(ProcurementDocument $document): ?string
    {
        return $document->purpose ?: Branch::query()->whereKey($document->branch_id)->value('name');
    }

    public function amountLabel(): ?string
    {
        return 'procurement.workflow.fields.estimated_total';
    }
}
