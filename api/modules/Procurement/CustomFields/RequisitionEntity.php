<?php

namespace Modules\Procurement\CustomFields;

use Modules\Procurement\Models\Requisition;

/** CF-01, PRC-20: purchase requisitions carry custom fields. */
class RequisitionEntity extends DocumentEntity
{
    public const KEY = 'procurement_requisition';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'procurement.custom_fields.requisition';
    }

    public function model(): string
    {
        return Requisition::class;
    }

    protected function permission(): string
    {
        return 'procurement.requisition';
    }
}
