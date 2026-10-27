<?php

namespace Modules\Salon\Appointments;

use App\Core\Rbac\ModuleRegistry;
use Brick\Math\BigDecimal;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Service;
use Ramsey\Uuid\Uuid;

/**
 * SAL-19: the products a finished service uses leave stock. With Inventory active, once per appointment, the products
 * of its services (item and quantity, summed) leave the branch's salon location through Inventory's `StockWrittenOff`
 * event (source `salon.consumption`, an adjustment out at cost). Without Inventory, or without a location to take from,
 * nothing leaves; a missing location is kept as an exception for a person to set the branch up.
 */
class Consumption
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function post(Appointment $appointment): void
    {
        if ($appointment->consumed_at !== null || ! $this->modules->isActive('inventory')) {
            return;
        }

        $lines = Line::query()->where('appointment_id', $appointment->id)->where('holds', true)->get();
        $services = Service::query()->whereKey($lines->pluck('service_id'))->get()->keyBy('id');
        $totals = [];
        $anchor = [];

        foreach ($lines as $line) {
            foreach ((array) ($services->get($line->service_id)?->products ?? []) as $product) {
                $item = (string) ($product['item_id'] ?? '');
                $qty = BigDecimal::of((string) ($product['qty'] ?? '0'));

                if ($item === '' || ! $qty->isPositive()) {
                    continue;
                }

                $totals[$item] = ($totals[$item] ?? BigDecimal::zero())->plus($qty);
                $anchor[$item] ??= $line->id;
            }
        }

        if ($totals === []) {
            return;
        }

        $locationId = $appointment->location_id ?? SalonBranch::query()->where('branch_id', $appointment->branch_id)->value('location_id');

        if ($locationId === null) {
            SalonException::query()->firstOrCreate(
                ['source_key' => 'consumption:'.$appointment->id],
                ['company_id' => $appointment->company_id, 'branch_id' => $appointment->branch_id, 'kind' => 'consumption_no_location', 'appointment_id' => $appointment->id, 'detail' => ['number' => $appointment->number]],
            );

            return;
        }

        $appointment->forceFill(['consumed_at' => now()])->save();

        StockWrittenOff::dispatch(
            (string) $appointment->tenant_id,
            $appointment->id,
            $appointment->company_id,
            $locationId,
            now()->utc()->toIso8601String(),
            $appointment->number,
            array_map(fn (string $item) => [
                'line_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, "salon:{$anchor[$item]}:{$item}")->toString(),
                'item_id' => $item,
                'qty' => (string) $totals[$item]->strippedOfTrailingZeros(),
            ], array_keys($totals)),
            null,
            'salon.consumption',
        );
    }
}
