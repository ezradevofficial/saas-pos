<?php

namespace Modules\FuelStation\Tanks;

use App\Core\Audit\Auditor;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FuelStation\Events\DeliveryRecorded;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Support\FuelError;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Support\Numbers;
use Modules\Inventory\Contracts\GoodsReceived;

/**
 * FUEL-25: litres received into a tank against a delivery note, measured by
 * a dip before and a dip after (cm, converted by the chart in force).
 *
 *   received = after − before + litres sold from the tank while offloading
 *   shortage = delivery note litres − received      (positive: short)
 *
 * What is posted to Inventory (`GoodsReceived`, at the delivery's unit cost)
 * is what was measured, not what the note says; a shortage beyond the
 * company's delivery tolerance is flagged `delivery_short` for the supplier
 * claim. Deliveries are immutable; the note number is unique per tank. The
 * tank is locked, so two deliveries or a delivery and a dip never interleave.
 */
class Deliveries
{
    public function __construct(
        private readonly Dips $dips,
        private readonly Numbers $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{tank_id: string, supplier_id?: ?string, note_number: string, note_litres: string|int, before_dip_cm: string|int, after_dip_cm: string|int, before_taken_at?: ?string, after_taken_at: string, unit_cost_minor: string|int, currency: string}  $data
     */
    public function record(array $data, User $by): Delivery
    {
        try {
            return DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($data, $by));
        } catch (UniqueConstraintViolationException) {
            throw FuelError::make(422, 'delivery_note_used', field: 'note_number');
        }
    }

    private function store(array $data, User $by): Delivery
    {
        $tank = Tank::query()->whereKey($data['tank_id'])->lockForUpdate()->first();

        if ($tank === null || $tank->archived_at !== null) {
            throw FuelError::make(422, 'tank_unknown', field: 'tank_id');
        }

        $supplier = $data['supplier_id'] ?? null;

        if ($supplier !== null && ! Party::query()->whereKey($supplier)->exists()) {
            throw FuelError::make(422, 'supplier_unknown', field: 'supplier_id');
        }

        $afterAt = CarbonImmutable::parse($data['after_taken_at'])->utc();
        $beforeAt = CarbonImmutable::parse($data['before_taken_at'] ?? $data['after_taken_at'])->utc()->min($afterAt);

        $before = $this->dips->record($tank, ['id' => (string) Str::uuid7(), 'kind' => 'delivery_before', 'dip_cm' => $data['before_dip_cm'], 'taken_at' => $beforeAt->toIso8601String()], $by, 'web');
        $after = $this->dips->record($tank, ['id' => (string) Str::uuid7(), 'kind' => 'delivery_after', 'dip_cm' => $data['after_dip_cm'], 'taken_at' => $afterAt->toIso8601String()], $by, 'web');
        // FUEL-25: what was sold from the tank while it was offloading is what the sales between the two dips say, never a typed figure.
        $sold = Litres::of((string) (FuelSale::query()->where('tank_id', $tank->id)->whereNull('voided_at')->where('sold_at', '>=', $beforeAt)->where('sold_at', '<=', $afterAt)
            ->selectRaw('coalesce(sum(litres), 0) as total')->value('total') ?? '0'));
        $received = Litres::of($after->litres)->minus(Litres::of($before->litres))->plus($sold);

        if (! $received->isPositive()) {
            throw FuelError::make(422, 'delivery_not_received', field: 'after_dip_cm');
        }

        $note = Litres::of($data['note_litres']);
        $shortage = $note->minus($received);
        $settings = Settings::for($tank->company_id);
        $allowed = $note->multipliedBy(BigDecimal::of((string) $settings->delivery_tolerance_percent))->dividedBy(100, 3, RoundingMode::HalfUp);
        $flags = [];

        if ($shortage->isGreaterThan($allowed)) {
            $flags[] = ['code' => 'delivery_short', 'litres' => (string) $shortage];
        } elseif ($shortage->negated()->isGreaterThan($allowed)) {
            $flags[] = ['code' => 'delivery_over', 'litres' => (string) $shortage->negated()];
        }

        $unit = (string) $data['unit_cost_minor'];
        $cost = Litres::value($unit, $received);
        $delivery = Delivery::create([
            'company_id' => $tank->company_id,
            'branch_id' => $tank->branch_id,
            'location_id' => $tank->location_id,
            'tank_id' => $tank->id,
            'supplier_id' => $supplier,
            'before_dip_id' => $before->id,
            'after_dip_id' => $after->id,
            'number' => $this->numbers->next(Numbers::DELIVERY, $tank->location_id),
            'note_number' => trim($data['note_number']),
            'note_litres' => (string) $note,
            'before_litres' => (string) Litres::of($before->litres),
            'after_litres' => (string) Litres::of($after->litres),
            'sold_during_litres' => (string) $sold,
            'received_litres' => (string) $received,
            'shortage_litres' => (string) $shortage,
            'unit_cost_minor' => $unit,
            'cost_minor' => (string) $cost,
            'currency' => $data['currency'],
            'received_at' => $afterAt,
            'flags' => $flags,
            'recorded_by' => $by->id,
        ]);

        $this->auditor->record('fuel.delivery.post', $delivery, null, [
            'number' => $delivery->number, 'note_litres' => (string) $note, 'received_litres' => (string) $received, 'shortage_litres' => (string) $shortage, 'cost_minor' => (string) $cost,
        ], ['user_id' => $by->id]);

        // FUEL-30: Inventory takes the measured litres in at the delivery's cost (nothing happens with Inventory off).
        $product = Product::query()->findOrFail($tank->product_id);
        event(new GoodsReceived($tank->tenant_id, $delivery->id, $tank->company_id, $tank->location_id, $afterAt->format('Y-m-d\TH:i:s.u\Z'), $delivery->number, [[
            'line_id' => $delivery->id, 'item_id' => $product->item_id, 'qty' => (string) $received, 'cost_minor' => (string) $cost, 'currency' => $data['currency'],
        ]], $by->id));
        DeliveryRecorded::dispatch($tank->tenant_id, $delivery->id, $tank->id, (string) $received, (string) $shortage);

        return $delivery->refresh();
    }
}
