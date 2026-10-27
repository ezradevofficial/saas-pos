<?php

namespace Modules\Pharmacy\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * PHA-10: an expiry alert already sent for a lot at a location (`near` or `expired`).
 *
 * @property string $lot_id
 * @property string $location_id
 * @property string $stage
 */
class ExpiryNotice extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    public const NEAR = 'near';

    public const EXPIRED = 'expired';

    protected $table = 'pharmacy_expiry_notices';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['notified_at' => 'immutable_datetime'];
    }
}
