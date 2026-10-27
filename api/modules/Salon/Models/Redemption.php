<?php

namespace Modules\Salon\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * SAL-18: a package session redeemed by a payment of a sale (once per payment).
 *
 * @property string $package_id
 * @property ?string $appointment_id
 * @property string $sale_id
 * @property string $sale_payment_id
 * @property ?string $service_id
 * @property int $value_minor
 * @property string $currency
 * @property string $status
 */
class Redemption extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'salon_redemptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_minor' => 'integer', 'redeemed_at' => 'immutable_datetime', 'reversed_at' => 'immutable_datetime'];
    }
}
