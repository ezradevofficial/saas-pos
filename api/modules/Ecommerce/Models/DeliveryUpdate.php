<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** ECOM-28: a status or location update a rider's phone uploaded (idempotent by its id). */
class DeliveryUpdate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_delivery_updates';

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'collected_minor' => 'integer'];
    }
}
