<?php

namespace Modules\Procurement\Documents;

use App\Core\Currency\Money;
use App\Core\MasterData\Parties\Party;
use App\Core\Workflow\DocumentTypes\FieldDefinition;
use Modules\Procurement\CustomFields\OrderEntity;
use Modules\Procurement\Models\ProcurementDocument;
use Modules\Procurement\Models\Order;

/**
 * WF-01, PRC-13: `procurement.po`, a purchase order approved through its
 * flow (RBAC-01's `procurement.po.approve`). Conditions may route on the
 * base total (CUR-04, frozen at submission), the total in the PO's
 * currency, the supplier, the branch and custom fields.
 */
class OrderType extends ProcurementFlowType
{
    public const KEY = 'procurement.po';

    public function key(): string
    {
        return self::KEY;
    }

    public function fields(): array
    {
        return [
            FieldDefinition::money('base_total', 'procurement.workflow.fields.base_total'),
            FieldDefinition::money('total', 'procurement.workflow.fields.total'),
            ...$this->placeFields(),
            FieldDefinition::reference('location', 'procurement.workflow.fields.location', 'core.location'),
            FieldDefinition::reference('supplier', 'procurement.workflow.fields.supplier', 'core.party'),
            FieldDefinition::string('currency', 'procurement.workflow.fields.currency'),
            ...$this->customFields(),
        ];
    }

    public function customFieldEntity(): ?string
    {
        return OrderEntity::KEY;
    }

    public function viewPermission(): string
    {
        return 'procurement.po.view';
    }

    public function actPermission(): string
    {
        return 'procurement.po.approve';
    }

    protected function model(): string
    {
        return Order::class;
    }

    protected function path(): string
    {
        return '/procurement/orders';
    }

    protected function flowKey(): string
    {
        return 'po';
    }

    protected function values(ProcurementDocument $document): array
    {
        return [
            'base_total' => self::money($document->base_total_minor, $document->base_currency),
            'total' => self::money($document->total_minor, $document->currency),
            'location' => $document->location_id,
            'supplier' => $document->party_id,
            'currency' => $document->currency,
        ];
    }

    protected function amount(ProcurementDocument $document): ?Money
    {
        return self::money($document->total_minor, $document->currency);
    }

    protected function title(ProcurementDocument $document): ?string
    {
        return Party::query()->whereKey($document->party_id)->value('name');
    }

    public function amountLabel(): ?string
    {
        return 'procurement.workflow.fields.total';
    }
}
