<?php

namespace Modules\Ecommerce\Delivery;

use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\DeliveryLocation;
use Modules\Ecommerce\Support\EcomError;

/**
 * ECOM-40 (KE DPA: minimum data): where a rider is while a delivery is
 * active (assigned or picked up). Each report adds a point to the
 * delivery's trail (at most `ecommerce.rider.max_trail_points`, the oldest
 * dropped) and moves its latest point when newer. A report for a delivery
 * that is not active is refused (`delivery_not_active`), so nothing is
 * kept about a rider between deliveries. The trail is cleared when the
 * delivery stops being active or changes rider (Delivery::booted); the
 * latest point stays on the delivery.
 */
final class RiderLocations
{
    /** Under the delivery's row lock (RiderUpdates). */
    public function record(Delivery $delivery, User $user, string $latitude, string $longitude, CarbonImmutable $at): void
    {
        if (! in_array($delivery->status, Delivery::ACTIVE, true) || $delivery->rider_id === null) {
            throw EcomError::make('delivery_not_active', status: 409);
        }

        DeliveryLocation::create([
            'company_id' => $delivery->company_id,
            'delivery_id' => $delivery->id,
            'rider_id' => $delivery->rider_id,
            'user_id' => $user->id,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'recorded_at' => $at,
        ]);

        $keep = max(1, (int) config('ecommerce.rider.max_trail_points', 500));
        $stale = DeliveryLocation::query()->where('delivery_id', $delivery->id)
            ->orderByDesc('recorded_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');

        if ($stale->isNotEmpty()) {
            DeliveryLocation::query()->whereKey($stale->all())->delete();
        }

        if ($delivery->located_at === null || $at->greaterThan($delivery->located_at)) {
            $delivery->forceFill(['last_latitude' => $latitude, 'last_longitude' => $longitude, 'located_at' => $at])->save();
        }
    }

    /**
     * The trail of an active delivery, oldest first; empty once it ended.
     *
     * @return list<array{latitude: string, longitude: string, at: string}>
     */
    public function trail(Delivery $delivery): array
    {
        if (! in_array($delivery->status, Delivery::ACTIVE, true)) {
            return [];
        }

        return DeliveryLocation::query()->where('delivery_id', $delivery->id)->whereNotNull('latitude')
            ->orderBy('recorded_at')->orderBy('id')->get()
            ->map(fn (DeliveryLocation $point) => ['latitude' => (string) $point->latitude, 'longitude' => (string) $point->longitude, 'at' => $point->recorded_at->toIso8601String()])
            ->values()->all();
    }

    /** The delivery's trail leaves the database (it ended, or another rider took it). */
    public static function clear(Delivery $delivery): void
    {
        DeliveryLocation::query()->where('delivery_id', $delivery->id)->delete();
    }
}
