<?php

namespace Modules\Inventory\Stock;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Inventory\Models\CostLayer;
use Modules\Inventory\Models\CostState;
use Modules\Inventory\Models\InventorySetting;

/**
 * INV-12: costing of one company's item, FIFO or weighted average, on its
 * cost state locked by the caller (StockLedger). Values are whole minor
 * units of the company's base currency; every rounding happens once, on a
 * share of a stored total, so layers and states never drift.
 *
 * - receive(): stock comes in at a total cost (or, when unknown, at the
 *   item's current unit cost: flagged `cost_estimated`, or `cost_unknown`
 *   at 0 when there is none). FIFO adds a layer; weighted average adds
 *   quantity and value. When the company was short (negative stock), the
 *   receipt first covers the shortfall: only what is left forms the layer
 *   (or the average), and the difference between the cost estimated when
 *   the shortfall was issued and this receipt's cost is returned as the
 *   variance (`cost_variance_minor`, for Accounting).
 * - issue(): stock goes out. FIFO consumes layers oldest first (the last
 *   unit of a layer takes its remaining cost exactly); weighted average
 *   takes its share of the value (all of it when everything goes). A
 *   shortfall beyond what is held is costed at the last receipt's unit
 *   cost (`costed_at_last_cost`), else the average, else 0 (`cost_unknown`).
 */
class CostEngine
{
    /**
     * @return array{cost: BigInteger, variance: BigInteger, flags: list<array{code: string}>}
     */
    public function receive(CostState $state, string $method, BigDecimal $qty, ?BigInteger $cost, string $movementId, CarbonImmutable $at, bool $setsLastCost): array
    {
        $flags = [];

        if ($cost === null) {
            $unit = $method === InventorySetting::FIFO ? $this->lastUnit($state) : $this->averageUnit($state);
            $flags[] = ['code' => $unit === null ? 'cost_unknown' : 'cost_estimated'];
            $cost = $unit === null ? BigInteger::zero() : Qty::round($unit->multipliedBy($qty));
            $setsLastCost = false;
        }

        $q0 = Qty::of((string) $state->qty);
        $v0 = BigInteger::of((string) $state->value_minor);
        $q = Qty::of($q0->plus($qty));

        if (! $q0->isNegative()) {
            $value = $v0->plus($cost);
            $layer = [$qty, $cost];
        } else {
            $value = match (true) {
                $q->isPositive() => Qty::share($cost, $q, $qty),
                $q->isZero() => BigInteger::zero(),
                default => Qty::share($v0, $q, $q0),
            };
            $layer = $q->isPositive() ? [$q, $value] : null;
        }

        if ($method === InventorySetting::FIFO && $layer !== null) {
            CostLayer::create([
                'id' => (string) Str::uuid7(),
                'company_id' => $state->company_id,
                'item_id' => $state->item_id,
                'movement_id' => $movementId,
                'received_at' => $at,
                'qty' => (string) $layer[0],
                'cost_minor' => (string) $layer[1],
                'remaining_qty' => (string) $layer[0],
                'remaining_cost_minor' => (string) $layer[1],
            ]);
        }

        $variance = $v0->plus($cost)->minus($value);
        $state->qty = (string) $q;
        $state->value_minor = (string) $value;

        if ($setsLastCost) {
            $state->last_cost_minor = (string) $cost;
            $state->last_cost_qty = (string) Qty::of($qty);
        }

        return ['cost' => $cost, 'variance' => $variance, 'flags' => $flags];
    }

    /**
     * @param  BigDecimal  $qty  the positive quantity going out
     * @return array{cost: BigInteger, flags: list<array{code: string}>}
     */
    public function issue(CostState $state, string $method, BigDecimal $qty): array
    {
        $q0 = Qty::of((string) $state->qty);
        $v0 = BigInteger::of((string) $state->value_minor);
        $available = $q0->isPositive() ? Qty::min($q0, $qty) : Qty::zero();
        $shortfallUnit = $this->lastUnit($state);
        $cost = BigInteger::zero();
        $flags = [];

        if ($available->isPositive()) {
            if ($method === InventorySetting::FIFO) {
                [$cost, $taken] = $this->consumeLayers($state, $available);
                $available = $taken;
            } else {
                $cost = $available->isEqualTo($q0) ? $v0 : Qty::share($v0, $available, $q0);
            }
        }

        $shortfall = Qty::of($qty->minus($available));

        if ($shortfall->isPositive()) {
            $flags[] = ['code' => $shortfallUnit === null ? 'cost_unknown' : 'costed_at_last_cost'];
            $cost = $cost->plus($shortfallUnit === null ? BigInteger::zero() : Qty::round($shortfallUnit->multipliedBy($shortfall)));
        }

        $state->qty = (string) Qty::of($q0->minus($qty));
        $state->value_minor = (string) $v0->minus($cost);

        return ['cost' => $cost, 'flags' => $flags];
    }

    /**
     * IMP-02: take back a receipt exactly (an undone opening stock import):
     * its quantity and cost, and under FIFO its own layer, which must still
     * be whole (the caller refuses once anything later moved the item).
     *
     * @return array{cost: BigInteger, flags: list<array{code: string}>}
     */
    public function reverseReceipt(CostState $state, string $method, string $movementId, BigDecimal $qty, BigInteger $cost): array
    {
        if ($method === InventorySetting::FIFO) {
            $layer = CostLayer::query()->where('movement_id', $movementId)->lockForUpdate()->first();

            if ($layer !== null) {
                $layer->remaining_qty = '0';
                $layer->remaining_cost_minor = '0';
                $layer->save();
            }
        }

        $state->qty = (string) Qty::of(BigDecimal::of((string) $state->qty)->minus($qty));
        $state->value_minor = (string) BigInteger::of((string) $state->value_minor)->minus($cost);

        return ['cost' => $cost, 'flags' => []];
    }

    /** The unit cost a move between locations is shown at (no cost changes hands). */
    public function displayUnit(CostState $state): ?BigDecimal
    {
        return $this->averageUnit($state);
    }

    /** The company's current average unit cost (value ÷ quantity while it holds some), else the last receipt's. */
    public function averageUnit(CostState $state): ?BigDecimal
    {
        $q = BigDecimal::of((string) $state->qty);

        if ($q->isPositive()) {
            return BigDecimal::of((string) $state->value_minor)->dividedBy($q, 20, RoundingMode::HalfUp);
        }

        return $this->lastReceiptUnit($state);
    }

    /** The last receipt's unit cost, else the average while some is held. */
    public function lastUnit(CostState $state): ?BigDecimal
    {
        return $this->lastReceiptUnit($state) ?? (BigDecimal::of((string) $state->qty)->isPositive() ? $this->averageUnit($state) : null);
    }

    private function lastReceiptUnit(CostState $state): ?BigDecimal
    {
        if ($state->last_cost_minor === null || $state->last_cost_qty === null || BigDecimal::of((string) $state->last_cost_qty)->isZero()) {
            return null;
        }

        return BigDecimal::of((string) $state->last_cost_minor)->dividedBy((string) $state->last_cost_qty, 20, RoundingMode::HalfUp);
    }

    /**
     * FIFO: take $qty from the oldest layers.
     *
     * @return array{0: BigInteger, 1: BigDecimal} the cost taken and the quantity the layers held of it
     */
    private function consumeLayers(CostState $state, BigDecimal $qty): array
    {
        $need = $qty;
        $cost = BigInteger::zero();

        $layers = CostLayer::query()
            ->where('company_id', $state->company_id)->where('item_id', $state->item_id)
            ->where('remaining_qty', '>', 0)
            ->orderBy('received_at')->orderBy('id')
            ->lockForUpdate()->get();

        foreach ($layers as $layer) {
            if (! $need->isPositive()) {
                break;
            }

            $remaining = BigDecimal::of((string) $layer->remaining_qty);
            $remainingCost = BigInteger::of((string) $layer->remaining_cost_minor);
            $take = Qty::min($remaining, $need);
            $taken = $take->isEqualTo($remaining) ? $remainingCost : Qty::share($remainingCost, $take, $remaining);

            $layer->remaining_qty = (string) Qty::of($remaining->minus($take));
            $layer->remaining_cost_minor = (string) $remainingCost->minus($taken);
            $layer->save();

            $cost = $cost->plus($taken);
            $need = Qty::of($need->minus($take));
        }

        return [$cost, Qty::of($qty->minus($need))];
    }
}
