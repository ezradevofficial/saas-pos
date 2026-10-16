<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-16: one step of an order's history. */
class OrderEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_order_events';

    protected function casts(): array
    {
        return ['at' => 'immutable_datetime'];
    }
}
