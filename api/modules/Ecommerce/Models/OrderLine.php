<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-11: a line of an order, priced by the server. */
class OrderLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_order_lines';

    protected function casts(): array
    {
        return ['promotion' => 'array', 'tax_inclusive' => 'boolean', 'line_no' => 'integer', 'unit_price_minor' => 'integer', 'gross_minor' => 'integer', 'discount_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }
}
