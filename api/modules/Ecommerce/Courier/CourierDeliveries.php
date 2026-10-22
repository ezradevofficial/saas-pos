<?php

namespace Modules\Ecommerce\Courier;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Modules\Ecommerce\Delivery\RiderUpdates;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Tx;

/**
 * MKT-04, ECOM-27, ECOM-28 (ADR 024): a courier working through the public
 * API, as the courier connector's install (an app principal) or an API key.
 *
 * - A courier is a rider of the company whose user is its principal, made
 *   the first time it books there (named after the app or key; audited as
 *   any rider). Staff stop a courier at a company by archiving that rider.
 * - Booking hands an unassigned delivery of an order that is accepted,
 *   being picked or ready to the courier, as assigning a rider does
 *   (Deliveries::assign, whose rider rule wants a person), and keeps the
 *   courier's tracking. Idempotent by the client's reference: the same
 *   reference and tracking answer the same; the same reference with other
 *   tracking is refused; a new reference on a delivery the courier holds
 *   updates its tracking.
 * - Status updates (picked up, delivered with the customer's delivery code,
 *   failed with a reason) go through RiderUpdates, the rider app's own
 *   service, as one update whose id is derived from the courier and the
 *   client's reference: sent again, it answers what it answered first.
 *   Location updates are never taken here, so a courier leaves no trail.
 *
 * Every change is audited as the principal (the delivery, rider and order
 * models), with the key or install in the entry's metadata (ADR 020).
 */
final class CourierDeliveries
{
    /** Order statuses whose delivery may be booked (as Deliveries::assign). */
    public const BOOKABLE_ORDER_STATUSES = [Order::ACCEPTED, Order::PICKING, Order::READY];

    /** Delivery statuses while a courier holds it (its tracking may still change). */
    public const HELD = [Delivery::ASSIGNED, Delivery::PICKED_UP];

    /** RiderUpdates codes and the HTTP status a courier gets for them. */
    private const STATUSES = [
        'delivery_unknown' => 404,
        'delivery_status_invalid' => 409,
        'code_locked' => 409,
        'status_invalid' => 409,
    ];

    public function __construct(private readonly RiderUpdates $updates) {}

    /** @return Collection<int, string> the ids of the riders that are $courier */
    public static function riderIds(User $courier): Collection
    {
        return Rider::query()->where('user_id', $courier->id)->pluck('id');
    }

    /** Whether $delivery is handed to $courier (its rider is the courier at that company). */
    public static function handedTo(Delivery $delivery, User $courier): bool
    {
        return $delivery->rider_id !== null && Rider::query()->whereKey($delivery->rider_id)->where('user_id', $courier->id)->exists();
    }

    /**
     * @param  array{reference: string, tracking_reference: string, tracking_url?: ?string}  $data  validated
     * @return array{delivery: Delivery, created: bool}
     */
    public function book(Delivery $delivery, User $courier, array $data): array
    {
        return Tx::run(function () use ($delivery, $courier, $data) {
            $delivery = Delivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $tracking = ['tracking_reference' => $data['tracking_reference'], 'tracking_url' => $data['tracking_url'] ?? null];

            if (self::handedTo($delivery, $courier)) {
                if ($delivery->courier_reference === $data['reference']) {
                    if ($delivery->tracking_reference !== $tracking['tracking_reference'] || $delivery->tracking_url !== $tracking['tracking_url']) {
                        throw self::error('reference_reused', 422, 'reference');
                    }

                    return ['delivery' => $delivery, 'created' => false];
                }

                if (! in_array($delivery->status, self::HELD, true)) {
                    throw self::error('delivery_status_invalid', 409);
                }

                $delivery->forceFill([...$tracking, 'courier_reference' => $data['reference']])->save();

                return ['delivery' => $delivery, 'created' => false];
            }

            $order = Order::query()->findOrFail($delivery->order_id);

            if ($delivery->status !== Delivery::UNASSIGNED || ! in_array($order->status, self::BOOKABLE_ORDER_STATUSES, true)) {
                throw self::error('delivery_not_assignable', 409);
            }

            $now = CarbonImmutable::now();
            $delivery->forceFill([
                ...$tracking,
                'rider_id' => $this->riderFor($courier, $delivery->company_id)->id,
                'status' => Delivery::ASSIGNED,
                'assigned_at' => $now,
                'courier_reference' => $data['reference'],
                'booked_at' => $now,
            ])->save();

            return ['delivery' => $delivery, 'created' => true];
        });
    }

    /**
     * @param  array{reference: string, status: string, occurred_at: string, code?: ?string, reason?: ?string}  $data  validated
     */
    public function status(Delivery $delivery, User $courier, array $data): void
    {
        $update = array_filter([
            'id' => self::updateId($courier, $data['reference']),
            'delivery_id' => $delivery->id,
            'kind' => 'status',
            'status' => $data['status'],
            'occurred_at' => $data['occurred_at'],
            'code' => $data['code'] ?? null,
            'note' => $data['reason'] ?? null,
        ], fn ($value) => $value !== null);

        $result = $this->updates->upload($courier, [$update])[0];

        if ($result['status'] !== 'stored') {
            $code = (string) ($result['error']['code'] ?? 'delivery_status_invalid');

            throw self::error($code, self::STATUSES[$code] ?? 422);
        }
    }

    /** A stable UUID (version 8) for the courier's reference, so a resend is the same rider update. */
    public static function updateId(User $courier, string $reference): string
    {
        $hex = hash('sha256', 'ecommerce.courier|'.$courier->id.'|'.$reference);
        $hex[12] = '8';
        $hex[16] = dechex(8 | (hexdec($hex[16]) & 3));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /** The courier's rider at the company, made on its first booking there; an archived or inactive one is refused. */
    private function riderFor(User $courier, string $companyId): Rider
    {
        $rider = Rider::query()->where('company_id', $companyId)->where('user_id', $courier->id)->first();

        if ($rider === null) {
            return Rider::create(['company_id' => $companyId, 'user_id' => $courier->id, 'name' => mb_substr((string) $courier->name, 0, 160), 'active' => true]);
        }

        if (! $rider->active || $rider->archived_at !== null) {
            throw self::error('courier_stopped', 403);
        }

        return $rider;
    }

    public static function error(string $code, int $status, ?string $field = null): ApiException
    {
        $message = Lang::has("courier.errors.{$code}") ? __("courier.errors.{$code}") : __("ecommerce.errors.{$code}");

        return new ApiException($status, $code, $message, $field === null ? [] : [$field => [$message]]);
    }
}
