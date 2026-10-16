<?php

namespace Modules\Ecommerce\Catalogue;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Modules\Ecommerce\Models\Product;
use Modules\Ecommerce\Models\Reservation;
use Modules\Ecommerce\Models\Store;
use Modules\Inventory\Contracts\StockLevels;

/**
 * ECOM-04, ECOM-14: what a store can sell of each product. With Inventory
 * active and stock tracked (a `stock` item): on hand at the store's
 * location (Inventory's read contract) − active reservations − the safety
 * buffer, never below zero. Otherwise the product's manual `in_stock` flag
 * (untracked: `available` is null). Placement calls this under the
 * location and item locks (Reservations), so what it reads is final.
 */
final class Availability
{
    /** "Only N left" is shown at or below this many units. */
    public const LOW = 5;

    /**
     * @param  iterable<Product>  $products  with their `item` loaded
     * @return array<string, array{tracked: bool, available: ?string, in_stock: bool}> by product id
     */
    public function of(Store $store, iterable $products): array
    {
        $products = collect($products);
        $tracked = $products->filter(fn (Product $p) => $p->track_stock && $p->item?->type === 'stock');
        $onHand = $tracked->isEmpty() ? [] : $this->onHand($store->location_id, $tracked->pluck('item_id')->unique()->values()->all());
        $reserved = $onHand === null || $tracked->isEmpty() ? [] : $this->reserved($store->location_id, $tracked->pluck('item_id')->unique()->values()->all());
        $buffer = BigDecimal::of((string) $store->safety_buffer);
        $trackedIds = $tracked->pluck('id')->flip();
        $result = [];

        foreach ($products as $product) {
            if ($onHand === null || ! $trackedIds->has($product->id)) {
                $result[$product->id] = ['tracked' => false, 'available' => null, 'in_stock' => (bool) $product->in_stock];

                continue;
            }

            $free = BigDecimal::of($onHand[$product->item_id] ?? '0')->minus($reserved[$product->item_id] ?? '0')->minus($buffer);
            $free = $free->isNegative() ? BigDecimal::zero() : $free;
            $result[$product->id] = ['tracked' => true, 'available' => (string) $free->strippedOfTrailingZeros(), 'in_stock' => $free->isPositive() && (bool) $product->in_stock];
        }

        return $result;
    }

    /** @param list<string> $itemIds */
    private function onHand(string $locationId, array $itemIds): ?array
    {
        return class_exists(StockLevels::class) ? app(StockLevels::class)->onHand($locationId, $itemIds) : null;
    }

    /**
     * Base quantities held by active reservations at $locationId.
     *
     * @param  list<string>  $itemIds
     * @return array<string, string>
     */
    private function reserved(string $locationId, array $itemIds): array
    {
        $now = CarbonImmutable::now();

        return Reservation::query()->where('location_id', $locationId)->whereIn('item_id', $itemIds)
            ->whereNull('released_at')->whereNull('consumed_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))
            ->groupBy('item_id')->selectRaw('item_id, sum(qty_base) as qty')->pluck('qty', 'item_id')
            ->map(fn ($qty) => (string) $qty)->all();
    }
}
