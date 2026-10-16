<?php

namespace Modules\Ecommerce\Models;

/** ECOM-15: a payment of an order (an online intent, cash on delivery, points or a gift card). */
class Payment extends EcomModel
{
    protected $table = 'ecom_payments';

    protected string $auditResource = 'payment';

    protected function casts(): array
    {
        return ['detail' => 'array', 'amount_minor' => 'integer', 'collected_at' => 'immutable_datetime'];
    }
}
