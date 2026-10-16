<?php

namespace Modules\Ecommerce\Models;

/** ECOM-30: a rider's cash-up of cash collected on delivery. */
class Cashup extends EcomModel
{
    protected $table = 'ecom_cashups';

    protected string $auditResource = 'cashup';

    protected function casts(): array
    {
        return ['expected_minor' => 'integer', 'counted_minor' => 'integer', 'variance_minor' => 'integer', 'deliveries' => 'integer', 'closed_at' => 'immutable_datetime'];
    }
}
