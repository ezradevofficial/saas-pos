<?php

namespace Modules\Ecommerce\Models;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Archivable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ecommerce\Courier\CourierDeliveries;

/** ECOM-26: a rider: a user of the tenant at a company. */
class Rider extends EcomModel
{
    use Archivable;

    protected $table = 'ecom_riders';

    protected string $auditResource = 'rider';

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /**
     * MKT-04, security review H1: a courier's rider (an API key's or app's
     * principal) archived or switched off gives back the deliveries it holds
     * that are not picked up yet (CourierDeliveries::release).
     */
    protected static function booted(): void
    {
        static::updated(function (Rider $rider) {
            $stopped = ($rider->wasChanged('archived_at') && $rider->archived_at !== null) || ($rider->wasChanged('active') && ! $rider->active);

            if ($stopped && User::query()->whereKey($rider->user_id)->where('status', User::STATUS_SERVICE)->exists()) {
                app(CourierDeliveries::class)->release($rider);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
