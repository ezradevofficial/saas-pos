<?php

namespace Modules\Ecommerce\Courier;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Modules\Ecommerce\Delivery\RiderUpdates;
use Modules\Ecommerce\Models\Delivery;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Rider;
use Modules\Ecommerce\Support\Tx;

/**
 * MKT-04, ECOM-27, ECOM-28 (ADR 024): a courier working through the public
 * API, as the courier connector's install (an app principal) or an API key.
 * Only service principals book and report; people who manage deliveries
 * use the staff and rider routes (security review M3).
 *
 * - A courier is a rider of the company whose user is its principal, made
 *   the first time it books there (named after the app or key; audited as
 *   any rider). Two first bookings at once make one rider (review L6).
 * - Staff stop a courier at a company by archiving that rider, or switching
 *   it off (review H1): every courier route refuses it there with 403
 *   `courier_stopped`, nothing is handed to it any more (no customer
 *   contact), and the deliveries it holds that are not picked up yet go
 *   back to waiting for a courier, their tracking cleared, audited. A
 *   delivery it already picked up stays for staff to confirm or return.
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

    /** RiderUpdates codes and what a courier gets for them. */
    private const STATUSES = [
        'delivery_unknown' => 404,
        'delivery_status_invalid' => 409,
        'code_locked' => 409,
        'status_invalid' => 409,
        'courier_stopped' => 403,
    ];

    public function __construct(private readonly RiderUpdates $updates) {}

    /**
     * The riders that are $user, read once per request: ids of those that
     * may work (active, not archived) and the companies where it is stopped.
     *
     * @return array{active: list<string>, stopped_companies: list<string>, any: bool}
     */
    public static function ridersOf(User $user): array
    {
        $request = request();
        $key = 'ecommerce.courier.riders.'.$user->id;

        if ($request->attributes->has($key)) {
            return $request->attributes->get($key);
        }

        $riders = Rider::query()->where('user_id', $user->id)->get(['id', 'company_id', 'active', 'archived_at']);
        $working = fn (Rider $r) => $r->active && $r->archived_at === null;
        $value = [
            'active' => $riders->filter($working)->pluck('id')->values()->all(),
            'stopped_companies' => $riders->reject($working)->pluck('company_id')->unique()->values()->all(),
            'any' => $riders->isNotEmpty(),
        ];
        $request->attributes->set($key, $value);

        return $value;
    }

    /** Forget what ridersOf() read in this request (a rider was made or changed). */
    public static function forget(User $user): void
    {
        request()->attributes->remove('ecommerce.courier.riders.'.$user->id);
    }

    /** @return Collection<int, string> the ids of the riders that are $courier and may work */
    public static function riderIds(User $courier): Collection
    {
        return collect(self::ridersOf($courier)['active']);
    }

    /** Whether $delivery is handed to $courier: its rider is the courier, active and not archived. */
    public static function handedTo(Delivery $delivery, User $courier): bool
    {
        return $delivery->rider_id !== null && in_array($delivery->rider_id, self::ridersOf($courier)['active'], true);
    }

    /** Whether $delivery waits for a courier (no rider, its order bookable); needs its order loaded or readable. */
    public static function waiting(Delivery $delivery): bool
    {
        return $delivery->status === Delivery::UNASSIGNED && in_array($delivery->order?->status, self::BOOKABLE_ORDER_STATUSES, true);
    }

    /** Review H1: a courier stopped at the company gets nothing there. */
    public static function assertNotStopped(User $courier, string $companyId): void
    {
        if (in_array($companyId, self::ridersOf($courier)['stopped_companies'], true)) {
            throw self::error('courier_stopped', 403);
        }
    }

    /** Review M3: only an API key's or an app's principal books and reports as a courier. */
    public static function assertService(User $user): void
    {
        if (! $user->isServicePrincipal()) {
            throw self::error('courier_keys_only', 403);
        }
    }

    /**
     * @param  array{reference: string, tracking_reference: string, tracking_url?: ?string}  $data  validated
     * @return array{delivery: Delivery, created: bool}
     */
    public function book(Delivery $delivery, User $courier, array $data): array
    {
        $result = Tx::run(function () use ($delivery, $courier, $data) {
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

        self::forget($courier);

        return $result;
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
            $code = $code === RiderUpdates::RIDER_STOPPED ? 'courier_stopped' : $code;

            throw self::error($code, self::STATUSES[$code] ?? 422);
        }
    }

    /**
     * Review H1: a courier's rider was archived or switched off. The
     * deliveries it holds that are not picked up go back to waiting for a
     * courier (no rider, tracking cleared; each change audited on the
     * delivery), and one entry on the rider names them and the picked-up
     * ones staff still have to confirm or return.
     */
    public function release(Rider $rider): void
    {
        Tx::run(function () use ($rider) {
            $held = Delivery::query()->where('rider_id', $rider->id)->whereIn('status', self::HELD)->lockForUpdate()->get();
            $released = [];

            foreach ($held->where('status', Delivery::ASSIGNED) as $delivery) {
                $delivery->forceFill([
                    'status' => Delivery::UNASSIGNED, 'rider_id' => null, 'assigned_at' => null,
                    'courier_reference' => null, 'tracking_reference' => null, 'tracking_url' => null, 'booked_at' => null,
                ])->save();
                $released[] = $delivery->id;
            }

            app(Auditor::class)->record('ecommerce.rider.courier_stopped', $rider, null, [
                'released_delivery_ids' => $released,
                'picked_up_delivery_ids' => $held->where('status', Delivery::PICKED_UP)->pluck('id')->values()->all(),
            ]);
        });
    }

    /** A stable UUID (version 8) for the courier's reference, so a resend is the same rider update. */
    public static function updateId(User $courier, string $reference): string
    {
        $hex = hash('sha256', 'ecommerce.courier|'.$courier->id.'|'.$reference);
        $hex[12] = '8';
        $hex[16] = dechex(8 | (hexdec($hex[16]) & 3));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20, 12);
    }

    /**
     * The courier's rider at the company, made on its first booking there;
     * an archived or inactive one is refused. Review L6: made in a savepoint,
     * so a booking that loses the race to make it reads the winner's row.
     */
    public function riderFor(User $courier, string $companyId): Rider
    {
        $find = fn () => Rider::query()->where('company_id', $companyId)->where('user_id', $courier->id)->first();
        $rider = $find();

        if ($rider === null) {
            try {
                $rider = DB::connection(TenantContext::CONNECTION)->transaction(fn () => Rider::create([
                    'company_id' => $companyId, 'user_id' => $courier->id, 'name' => mb_substr((string) $courier->name, 0, 160), 'active' => true,
                ]));
            } catch (UniqueConstraintViolationException) {
                $rider = $find() ?? throw self::error('delivery_not_assignable', 409);
            }
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
