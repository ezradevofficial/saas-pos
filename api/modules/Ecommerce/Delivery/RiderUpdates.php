<?php

namespace Modules\Ecommerce\Delivery;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\DeliveryUpdate;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Orders\OrderFlow;
use Modules\Ecommerce\Support\EcomError;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-28, ECOM-29, NFR-04: a rider's status and location updates, queued
 * on the phone while offline and uploaded later, idempotent by the UUID v7
 * the phone made: an update the server already has answers what it
 * answered then (a resend with other content is `payload_mismatch`).
 *
 * - `location`: a point of the delivery's trail and its latest position
 *   when newer (RiderLocations), only while the delivery is active
 *   (`delivery_not_active` otherwise); the update row keeps no position.
 * - `picked_up` (assigned): the order goes out for delivery (hand-over,
 *   OrderFlow::dispatch), the customer gets the delivery code.
 * - `delivered` (picked up): proof by the customer's delivery code
 *   (checked here, never on the phone; wrong codes are counted under the
 *   delivery's row lock and 5 lock it: then no delivered update is taken,
 *   a photo neither, and staff confirm it) or a photo uploaded first; cash collected for a COD order
 *   (the amount due unless the rider says otherwise; a difference is
 *   flagged on the delivery).
 * - `failed`: before pick-up the delivery goes back to unassigned; after
 *   it the order is returned to its location (OrderFlow::return).
 *
 * Only the rider's own deliveries are reached (another's is
 * `delivery_unknown`). A refused update is kept with its reason, so a
 * resend learns the same answer.
 *
 * Review M1: an update's fingerprint is an HMAC with a key of its own
 * (`ecommerce.rider.update_hash_key`, else derived from the app key), so a
 * stored row never lets anyone search back for a position or a delivery
 * code. Review M5: a location point leaves no row at all: its id and
 * answer stay in the cache for 9 days (longer than a phone keeps a queue,
 * NFR-04), which answers a resend the same way; a lost cache only lets a
 * resent point into the trail twice. `occurred_at` is held between 8 days
 * ago and 5 minutes ahead (a phone's wrong clock never dates a status).
 */
final class RiderUpdates
{
    public const MAX_CODE_ATTEMPTS = 5;

    public const LOCATION_IDS_DAYS = 9;

    public function __construct(private readonly OrderFlow $flow, private readonly RiderLocations $locations) {}

    /**
     * @param  list<array<string, mixed>>  $updates  validated
     * @return list<array{id: string, status: string, error?: array{code: string, message: string, retryable: bool}}>
     */
    public function upload(User $user, array $updates): array
    {
        $results = [];

        foreach ($updates as $update) {
            $results[] = $this->one($user, $update);
        }

        return $results;
    }

    /** @param array<string, mixed> $update */
    private function one(User $user, array $update): array
    {
        $id = (string) $update['id'];
        $hash = self::payloadHash($update);
        $cacheKey = 'ecom-rider-location:'.app(TenantContext::class)->require().':'.$id;
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            if (! hash_equals((string) ($cached['hash'] ?? ''), $hash)) {
                return self::rejected($id, 'payload_mismatch');
            }

            return ($cached['error'] ?? null) === null ? ['id' => $id, 'status' => 'stored'] : self::rejected($id, (string) $cached['error']);
        }

        $known = DeliveryUpdate::query()->find($id);

        if ($known !== null) {
            if (! hash_equals($known->payload_hash, $hash)) {
                return self::rejected($id, 'payload_mismatch');
            }

            return $known->outcome === 'applied' ? ['id' => $id, 'status' => 'stored'] : self::rejected($id, (string) $known->error);
        }

        $riderIds = Rider::query()->where('user_id', $user->id)->pluck('id');
        $delivery = Delivery::query()->whereKey($update['delivery_id'])->whereIn('rider_id', $riderIds)->first();

        if ($delivery === null) {
            return self::rejected($id, 'delivery_unknown');
        }

        $now = CarbonImmutable::now();
        $at = CarbonImmutable::parse((string) $update['occurred_at'])->utc();
        $at = $at->max($now->subDays(8))->min($now->addMinutes(5));
        $error = null;

        try {
            // A wrong code is a refusal that commits (its attempt counts), so it is returned, not thrown.
            $error = Tx::run(function () use ($update, $delivery, $user, $at) {
                $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();

                if ($update['kind'] === 'location') {
                    $this->locations->record($delivery, $user, (string) $update['latitude'], (string) $update['longitude'], $at);

                    return null;
                }

                return $this->status($delivery, $update, $user, $at);
            });
        } catch (ApiException $e) {
            $error = $e->errorCode;
        }

        if ($update['kind'] === 'location') {
            Cache::put($cacheKey, ['hash' => $hash, 'error' => $error], $now->addDays(self::LOCATION_IDS_DAYS));

            return $error === null ? ['id' => $id, 'status' => 'stored'] : self::rejected($id, $error);
        }

        DeliveryUpdate::query()->insertOrIgnore([
            'id' => $id,
            'tenant_id' => $delivery->tenant_id,
            'delivery_id' => $delivery->id,
            'rider_id' => $delivery->rider_id,
            'user_id' => $user->id,
            'kind' => $update['kind'],
            'status' => $update['status'] ?? null,
            // ECOM-40: the position lives only in the delivery's trail (cleared when it ends), never here.
            'latitude' => null,
            'longitude' => null,
            'collected_minor' => $update['collected_minor'] ?? null,
            'note' => isset($update['note']) ? mb_substr((string) $update['note'], 0, 500) : null,
            'payload_hash' => $hash,
            'occurred_at' => $at,
            'received_at' => CarbonImmutable::now(),
            'outcome' => $error === null ? 'applied' : 'rejected',
            'error' => $error,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $error === null ? ['id' => $id, 'status' => 'stored'] : self::rejected($id, $error);
    }

    /**
     * @param  array<string, mixed>  $update
     * @return ?string a refusal that keeps what was counted (`code_invalid`)
     */
    private function status(Delivery $delivery, array $update, User $user, CarbonImmutable $at): ?string
    {
        $order = Order::query()->findOrFail($delivery->order_id);

        switch ($update['status']) {
            case 'picked_up':
                if ($delivery->status === Delivery::PICKED_UP) {
                    return null;
                }

                if ($delivery->status !== Delivery::ASSIGNED) {
                    throw EcomError::make('delivery_status_invalid', status: 409);
                }

                $this->flow->dispatch($order, $user, $at);

                return null;

            case 'delivered':
                if ($delivery->status === Delivery::DELIVERED) {
                    return null;
                }

                if ($delivery->status !== Delivery::PICKED_UP) {
                    throw EcomError::make('delivery_status_invalid', status: 409);
                }

                // Locked after five wrong codes: no delivered update at all, a photo neither (staff confirm it).
                if ($delivery->code_locked) {
                    throw EcomError::make('code_locked');
                }

                $code = isset($update['code']) ? (string) $update['code'] : null;

                if ($code !== null) {
                    if (! hash_equals((string) $order->delivery_code_hash, OrderFlow::codeHash($order->id, $code))) {
                        $attempts = $delivery->code_attempts + 1;
                        $delivery->forceFill(['code_attempts' => $attempts, 'code_locked' => $attempts >= self::MAX_CODE_ATTEMPTS])->save();

                        return 'code_invalid';
                    }

                    $delivery->proof_kind = 'code';
                } elseif ($delivery->proof_kind !== 'photo') {
                    throw EcomError::make('proof_required');
                }

                $delivery->forceFill(['status' => Delivery::DELIVERED, 'delivered_at' => $at])->save();
                $this->flow->deliver($order, $user, $at, isset($update['collected_minor']) ? (int) $update['collected_minor'] : null);

                return null;

            case 'failed':
                $reason = trim((string) ($update['note'] ?? '')) ?: __('ecommerce.deliveries.failed_default');

                if ($delivery->status === Delivery::ASSIGNED) {
                    $delivery->forceFill(['status' => Delivery::UNASSIGNED, 'rider_id' => null, 'assigned_at' => null, 'reason' => mb_substr($reason, 0, 500)])->save();

                    return null;
                }

                if ($delivery->status !== Delivery::PICKED_UP) {
                    throw EcomError::make('delivery_status_invalid', status: 409);
                }

                $this->flow->return($order, $user, $reason);

                return null;
        }

        return null;
    }

    /** Review M1: HMAC of the update's own fields (the inner sha256 is what older rows kept, re-keyed by a migration). */
    public static function payloadHash(array $update): string
    {
        $fields = array_intersect_key($update, array_flip(['id', 'delivery_id', 'kind', 'status', 'latitude', 'longitude', 'occurred_at', 'code', 'collected_minor', 'note']));

        return self::keyed(hash('sha256', (string) json_encode($fields)));
    }

    public static function keyed(string $inner): string
    {
        $key = (string) config('ecommerce.rider.update_hash_key');

        return hash_hmac('sha256', $inner, $key !== '' ? $key : hash_hmac('sha256', 'ecommerce.rider-updates', (string) config('app.key')));
    }

    private static function rejected(string $id, string $code): array
    {
        return ['id' => $id, 'status' => 'rejected', 'error' => ['code' => $code, 'message' => __("ecommerce.errors.{$code}"), 'retryable' => false]];
    }
}
