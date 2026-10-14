<?php

namespace Modules\Procurement\CustomFields;

use Modules\Procurement\Models\Order;

/** CF-01, PRC-20: purchase orders carry custom fields (also on the PO template). */
class OrderEntity extends DocumentEntity
{
    public const KEY = 'procurement_po';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'procurement.custom_fields.po';
    }

    public function model(): string
    {
        return Order::class;
    }

    protected function permission(): string
    {
        return 'procurement.po';
    }
}
