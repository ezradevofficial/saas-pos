<?php

namespace Modules\Ecommerce\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Ecommerce\Delivery\RiderLocations;

/** ECOM-27: the delivery of an order by a rider. */
class Delivery extends EcomModel
{
    protected $table = 'ecom_deliveries';

    protected string $auditResource = 'delivery';

    /**
     * ECOM-40 (KE DPA: minimum data): the rider's position is never written to
     * the append-only audit log, where it could not be cleared.
     */
    protected array $auditHidden = ['last_latitude', 'last_longitude', 'located_at'];

    protected function casts(): array
    {
        return ['flags' => 'array', 'assigned_at' => 'immutable_datetime', 'picked_up_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'failed_at' => 'immutable_datetime', 'located_at' => 'immutable_datetime', 'collected_at' => 'immutable_datetime', 'cod_due_minor' => 'integer', 'cod_collected_minor' => 'integer', 'code_attempts' => 'integer', 'code_locked' => 'boolean', 'proof_photo_size' => 'integer', 'proof_photo_at' => 'immutable_datetime'];
    }

    public const UNASSIGNED = 'unassigned';

    public const ASSIGNED = 'assigned';

    public const PICKED_UP = 'picked_up';

    public const DELIVERED = 'delivered';

    public const FAILED = 'failed';

    /** ECOM-40: a delivery whose rider may report where they are (and whose trail is kept). */
    public const ACTIVE = [self::ASSIGNED, self::PICKED_UP];

    /**
     * ECOM-40: the trail is kept only while the delivery is active with the
     * same rider; a new rider also starts without the previous one's latest
     * point.
     */
    protected static function booted(): void
    {
        static::updated(function (Delivery $delivery) {
            // MKT-04, security review L7: a courier's tracking goes with it: when the rider changes
            // (unless a booking sets new tracking in the same change), or the delivery fails or goes
            // back to waiting before pick-up.
            $tracking = ['courier_reference', 'tracking_reference', 'tracking_url', 'booked_at'];
            $riderChanged = $delivery->wasChanged('rider_id') && ! $delivery->wasChanged('courier_reference');
            $endedEarly = $delivery->wasChanged('status') && in_array($delivery->status, [self::UNASSIGNED, self::FAILED], true) && $delivery->picked_up_at === null;

            if (($riderChanged || $endedEarly) && collect($tracking)->contains(fn (string $field) => $delivery->getAttribute($field) !== null)) {
                $clear = array_fill_keys($tracking, null);
                static::query()->whereKey($delivery->id)->update($clear);
                $delivery->forceFill($clear)->syncOriginal();
            }

            if ($delivery->wasChanged('rider_id')) {
                RiderLocations::clear($delivery);
                static::query()->whereKey($delivery->id)->update(['last_latitude' => null, 'last_longitude' => null, 'located_at' => null]);
                $delivery->forceFill(['last_latitude' => null, 'last_longitude' => null, 'located_at' => null])->syncOriginal();
            } elseif ($delivery->wasChanged('status') && ! in_array($delivery->status, self::ACTIVE, true)) {
                // Review M3: the trail and the last position go when it ends; only the time stays (ECOM-21).
                RiderLocations::clear($delivery);
                static::query()->whereKey($delivery->id)->update(['last_latitude' => null, 'last_longitude' => null]);
                $delivery->forceFill(['last_latitude' => null, 'last_longitude' => null])->syncOriginal();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function rider(): BelongsTo
    {
        return $this->belongsTo(Rider::class);
    }
}
