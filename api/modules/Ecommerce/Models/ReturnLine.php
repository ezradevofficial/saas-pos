<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-42, ECOM-43: a line of a return: `in` (back from the customer, a share of an order line) or `out` (the exchange item). */
class ReturnLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const IN = 'in';

    public const OUT = 'out';

    protected $guarded = [];

    protected $table = 'ecom_return_lines';

    protected function casts(): array
    {
        return ['promotion' => 'array', 'tax_inclusive' => 'boolean', 'line_no' => 'integer', 'unit_price_minor' => 'integer', 'gross_minor' => 'integer', 'discount_minor' => 'integer', 'net_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];
    }
}
