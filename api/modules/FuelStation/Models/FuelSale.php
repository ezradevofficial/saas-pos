<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-18: one fuel line of a POS sale, linked to a shift and a nozzle (or flagged). */
class FuelSale extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fuel_sales';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'total_minor' => 'integer',
            'odometer' => 'integer',
            'sold_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }
}
