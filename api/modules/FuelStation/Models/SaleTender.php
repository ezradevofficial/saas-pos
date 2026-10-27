<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-12: the fuel share of a sale's (or refund's) payments by tender kind. */
class SaleTender extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fuel_sale_tenders';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
