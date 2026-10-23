<?php

namespace Modules\Ecommerce\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * ECOM-40: a point of a rider's trail while their delivery is active. Kept
 * only until the delivery ends (KE DPA: minimum data); the latest point
 * stays on the delivery. Not audited: the audit log is append-only, so a
 * position written there could never be cleared.
 */
class DeliveryLocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = [];

    protected $table = 'ecom_delivery_locations';

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
