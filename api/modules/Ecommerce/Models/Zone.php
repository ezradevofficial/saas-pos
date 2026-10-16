<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\Archivable;

/** ECOM-10: a delivery zone of a store and its fee. */
class Zone extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_zones';

    protected string $auditResource = 'zone';

    protected function casts(): array
    {
        return ['areas' => 'array', 'fee_minor' => 'integer', 'free_over_minor' => 'integer', 'min_order_minor' => 'integer', 'active' => 'boolean', 'position' => 'integer'];
    }
}
