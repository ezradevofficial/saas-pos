<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\Archivable;

/** ECOM-08: an online customer (not a tenant user), linked to a core party. */
class Customer extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_customers';

    protected string $auditResource = 'customer';

    protected function casts(): array
    {
        return ['consent' => 'boolean', 'last_signed_in_at' => 'immutable_datetime'];
    }
}
