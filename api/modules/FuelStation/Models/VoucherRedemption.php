<?php

namespace Modules\FuelStation\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** FUEL-21: value taken from a voucher by one payment of one sale. */
class VoucherRedemption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'fuel_voucher_redemptions';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'redeemed_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'flags' => 'array',
        ];
    }
}
