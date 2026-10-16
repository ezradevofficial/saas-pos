<?php

namespace Modules\Ecommerce\Orders;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Catalogue\Availability;
use Modules\Ecommerce\Models\Order;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Reservation;
use Modules\Ecommerce\Models\Store;
use Modules\Ecommerce\Support\Tx;

/**
 * ECOM-14: stock an order holds at its location. Placing takes an advisory
 * lock per location and item, in item id order (so two placements never
 * deadlock), before availability is read; the reservation is written in
 * the same transaction, so two orders can never take the last unit.
 * Reservations are released (cancelled, expired) or consumed (handed over),
 * never deleted.
 */
final class Reservations
{
    /** @param list<string> $itemIds */
    public function lock(string $locationId, array $itemIds): void
    {
        $ids = array_values(array_unique($itemIds));
        sort($ids);

        foreach ($ids as $id) {
            Tx::lock("ecom:stock:{$locationId}:{$id}");
        }
    }

    /** @param list<array<string, mixed>> $lines Pricing lines */
    public function reserve(Order $order, array $lines, ?CarbonImmutable $expiresAt): void
    {
        foreach ($lines as $line) {
            if (! $line['tracked']) {
                continue;
            }

            Reservation::create([
                'order_id' => $order->id,
                'item_id' => $line['item_id'],
                'location_id' => $order->location_id,
                'qty_base' => $line['qty_base'],
                'expires_at' => $expiresAt,
            ]);
        }
    }

    /**
     * ECOM-14: whether an order whose reservation lapsed can still have its
     * stock: under the location and item locks, what is free (on hand −
     * other active reservations − buffer) covers each tracked line.
     */
    public function stillAvailable(Order $order): bool
    {
        $reservations = Reservation::query()->where('order_id', $order->id)->whereNull('released_at')->whereNull('consumed_at')->get();

        if ($reservations->isEmpty()) {
            return true;
        }

        $this->lock($order->location_id, $reservations->pluck('item_id')->all());
        $store = Store::query()->findOrFail($order->store_id);
        $products = Product::query()->where('store_id', $store->id)->whereIn('item_id', $reservations->pluck('item_id'))->with('item')->get();
        $available = app(Availability::class)->of($store, $products);

        foreach ($reservations->groupBy('item_id') as $itemId => $rows) {
            $product = $products->firstWhere('item_id', $itemId);
            $free = $product === null ? null : $available[$product->id];

            if ($free !== null && $free['tracked'] && BigDecimal::of((string) $free['available'])->isLessThan(self::sum($rows))) {
                return false;
            }
        }

        return true;
    }

    /** @param iterable<Reservation> $rows */
    private static function sum(iterable $rows): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($rows as $row) {
            $total = $total->plus((string) $row->qty_base);
        }

        return $total;
    }

    /** A paid or cash on delivery order holds its stock until hand-over. */
    public function hold(Order $order): void
    {
        Reservation::query()->where('order_id', $order->id)->whereNull('released_at')->whereNull('consumed_at')->update(['expires_at' => null, 'updated_at' => now()]);
    }

    public function release(Order $order): void
    {
        Reservation::query()->where('order_id', $order->id)->whereNull('released_at')->whereNull('consumed_at')->update(['released_at' => now(), 'updated_at' => now()]);
    }

    public function consume(Order $order): void
    {
        Reservation::query()->where('order_id', $order->id)->whereNull('released_at')->whereNull('consumed_at')->update(['consumed_at' => now(), 'updated_at' => now()]);
    }
}
