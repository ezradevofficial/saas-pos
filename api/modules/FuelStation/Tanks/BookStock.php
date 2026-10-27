<?php

namespace Modules\FuelStation\Tanks;

use Brick\Math\BigDecimal;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\Litres;

/**
 * FUEL-24: a tank's book stock now: the litres at its last reconciled dip
 * plus the deliveries and minus the live fuel sales no reconciliation has
 * counted yet. Null until the tank has a baseline (its first dip).
 */
class BookStock
{
    public function of(Tank $tank): ?BigDecimal
    {
        if ($tank->baseline_litres === null) {
            return null;
        }

        $sales = FuelSale::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->whereNull('voided_at')->selectRaw('coalesce(sum(litres), 0) as total')->value('total');
        $deliveries = Delivery::query()->where('tank_id', $tank->id)->whereNull('reconciliation_id')->selectRaw('coalesce(sum(received_litres), 0) as total')->value('total');

        return Litres::of($tank->baseline_litres)->plus(Litres::of((string) $deliveries))->minus(Litres::of((string) $sales));
    }
}
