<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-14: stock an order holds at its location. */
class Reservation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_reservations';

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }
}
