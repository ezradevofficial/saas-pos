<?php

namespace Modules\FuelStation\Sales;

use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\SaleTender;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Models\VoucherRedemption;
use Modules\FuelStation\Prices\Prices;
use Modules\FuelStation\Shifts\Settlements;
use Modules\FuelStation\Shifts\Tenders;
use Modules\FuelStation\Support\Litres;
use Modules\POS\Sync\Flags;

/**
 * FUEL-12, FUEL-15, FUEL-18, FUEL-19, FUEL-20: what the POS sale events mean
 * for the forecourt. A sale of a fuel product at a station is recorded line
 * by line (litres, price, nozzle, tank, attendant, shift, vehicle) with
 * review flags, never refused (the device wins, NFR-04): `no_shift`,
 * `shift_unknown`, `shift_other_station`, `outside_shift`, `nozzle_missing`,
 * `nozzle_unknown`, `nozzle_not_on_shift`, `product_mismatch`,
 * `price_missing`, `price_differs`, `attendant_differs`, `litres_rounded`,
 * `vehicle_required`, `vehicle_unknown`, `vehicle_blocked`,
 * `vehicle_limit`, `vehicle_fuel_differs`, `vehicle_customer_differs`.
 * The fuel share of the payments by tender kind is kept for the settlement
 * (Tenders). A sale, void or refund for a shift that is already closed
 * recomputes its settlements, which reopens a decided one when the figures
 * changed (Settlements). Idempotent per sale line, and per sale for tenders.
 * The events carry the POS snapshot, so no POS table is read.
 */
class FuelSales
{
    /** A sale may be dated this far outside its shift's open period (clock drift) before it is flagged. */
    private const DRIFT_MINUTES = 5;

    /** The POS compares a line with the core price lists, which do not hold fuel; the module checks the station's fuel price itself (FUEL-28). */
    private const POS_PRICE_FLAGS = ['price_unknown', 'price_differs', 'list_price_missing', 'tax_differs'];

    public function __construct(private readonly Prices $prices, private readonly Settlements $settlements) {}

    /** Whether the snapshot has a line of a fuel product at one of the tenant's stations. */
    public function concerns(array $sale): bool
    {
        return $this->fuelLines($sale) !== [];
    }

    /** @param array<string, mixed> $sale SaleSnapshot::sale() */
    public function record(array $sale): void
    {
        $station = Station::query()->where('location_id', $sale['location_id'] ?? '')->first();
        $lines = $station === null ? [] : $this->fuelLines($sale);

        if ($lines === []) {
            return;
        }

        try {
            DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->store($station, $sale, $lines));
        } catch (UniqueConstraintViolationException) {
            // The same sale recorded meanwhile (a retry of the queued job): nothing more to do.
        }
    }

    /** @param array<string, mixed> $sale */
    public function voided(array $sale, ?string $voidedAt): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($sale, $voidedAt) {
            $at = CarbonImmutable::parse($voidedAt ?? now())->utc();
            $rows = FuelSale::query()->where('sale_id', $sale['id'] ?? '')->whereNull('voided_at')->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return;
            }

            FuelSale::query()->whereKey($rows->pluck('id'))->update(['voided_at' => $at]);
            SaleTender::query()->where('sale_id', $sale['id'])->whereNull('voided_at')->update(['voided_at' => $at]);
            $this->settleShifts($rows->pluck('shift_id')->filter()->unique()->all(), 'void');
        });
    }

    /** @param array<string, mixed> $refund SaleSnapshot::refund() */
    public function refunded(array $refund): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($refund) {
            $lineIds = array_values(array_filter(array_column($refund['lines'] ?? [], 'sale_line_id')));
            $rows = $lineIds === [] ? collect() : FuelSale::query()->whereIn('sale_line_id', $lineIds)->lockForUpdate()->get();

            if ($rows->isEmpty() || SaleTender::query()->where('refund_id', $refund['id'])->exists()) {
                return;
            }

            $fuelTotal = collect($refund['lines'])->filter(fn ($l) => $rows->contains('sale_line_id', $l['sale_line_id'] ?? null))
                ->reduce(fn (BigInteger $sum, array $l) => $sum->plus((string) $l['total_minor']), BigInteger::zero());
            $net = Tenders::net($refund['payments'] ?? [], '0');
            $share = Tenders::fuelShare($net, $fuelTotal, BigInteger::of((string) ($refund['total_minor'] ?? '0')));
            $first = $rows->first();

            foreach ($share as $kind => $amount) {
                if (! $amount->isZero()) {
                    SaleTender::create([
                        'sale_id' => $refund['sale_id'], 'refund_id' => $refund['id'], 'shift_id' => $first->shift_id, 'attendant_id' => $first->attendant_id,
                        'kind' => $kind, 'amount_minor' => (string) $amount->negated(), 'currency' => $refund['currency'],
                    ]);
                }
            }

            foreach ($rows as $row) {
                $row->forceFill(['flags' => [...($row->flags ?? []), ['code' => 'refunded', 'refund_id' => $refund['id']]]])->save();
            }

            $this->settleShifts($rows->pluck('shift_id')->filter()->unique()->all(), 'refund');
        });
    }

    /**
     * FUEL-18: a shift just reached the server: sales that named it before it
     * existed here join it now (their nozzle, attendant and tenders too).
     */
    public function adopt(Shift $shift): void
    {
        $orphans = FuelSale::query()->where('claimed_shift_id', $shift->id)->whereNull('shift_id')->lockForUpdate()->get();

        if ($orphans->isEmpty()) {
            return;
        }

        $rows = ShiftNozzle::query()->where('shift_id', $shift->id)->get()->keyBy('nozzle_id');

        foreach ($orphans as $sale) {
            $row = $sale->nozzle_id === null ? null : $rows->get($sale->nozzle_id);
            $flags = array_values(array_filter($sale->flags ?? [], fn ($f) => ! in_array($f['code'] ?? '', ['shift_unknown', 'nozzle_not_on_shift'], true)));

            if ($sale->nozzle_id !== null && $row === null) {
                $flags[] = ['code' => 'nozzle_not_on_shift'];
            }

            $sale->forceFill(['shift_id' => $shift->id, 'shift_nozzle_id' => $row?->id, 'attendant_id' => $row?->attendant_id, 'flags' => $flags])->save();
            SaleTender::query()->where('sale_id', $sale->sale_id)->whereNull('refund_id')->whereNull('shift_id')->update(['shift_id' => $shift->id, 'attendant_id' => $row?->attendant_id]);
        }
    }

    /** @param list<string> $shiftIds */
    private function settleShifts(array $shiftIds, string $cause): void
    {
        foreach (Shift::query()->whereKey($shiftIds)->where('status', Shift::CLOSED)->orderBy('id')->get() as $shift) {
            $this->settlements->settle($shift, null, null, $cause);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $lines  the sale's fuel lines
     * @param  array<string, mixed>  $sale
     */
    private function store(Station $station, array $sale, array $lines): void
    {
        $soldAt = CarbonImmutable::parse($sale['sold_at'])->utc();
        $ext = is_array($sale['extensions']['fuel'] ?? null) ? $sale['extensions']['fuel'] : [];
        $extLines = collect((array) ($ext['lines'] ?? []))->filter(fn ($l) => is_array($l))->keyBy('line_id');
        $saleFlags = new Flags;
        $claimed = isset($ext['shift_id']) && is_string($ext['shift_id']) && Str::isUuid($ext['shift_id']) ? $ext['shift_id'] : null;
        $shift = null;

        if ($claimed === null) {
            $saleFlags->add('no_shift');
        } else {
            // Locked: a close in progress finishes first, so the settlement below sees this sale (FUEL-10).
            $shift = Shift::query()->whereKey($claimed)->lockForUpdate()->first();

            if ($shift === null) {
                $saleFlags->add('shift_unknown');
            } elseif ($shift->station_id !== $station->id) {
                $saleFlags->add('shift_other_station');
                $shift = null;
                $claimed = null;
            } elseif ($soldAt->lessThan($shift->opened_at->subMinutes(self::DRIFT_MINUTES)) || ($shift->closed_at !== null && $soldAt->greaterThan($shift->closed_at->addMinutes(self::DRIFT_MINUTES)))) {
                $saleFlags->add('outside_shift');
            }
        }

        $products = Product::query()->whereIn('item_id', array_column($lines, 'item_id'))->get()->keyBy('item_id');
        $nozzles = Nozzle::query()->whereKey(array_values(array_filter($extLines->pluck('nozzle_id')->all(), fn ($id) => is_string($id) && Str::isUuid($id))))->where('station_id', $station->id)->get()->keyBy('id');
        $tanks = Tank::query()->whereKey($nozzles->pluck('tank_id')->unique())->get()->keyBy('id');
        $shiftNozzles = $shift === null ? collect() : ShiftNozzle::query()->where('shift_id', $shift->id)->get()->keyBy('nozzle_id');
        $payments = (array) ($sale['payments'] ?? []);
        $credit = collect($payments)->contains(fn ($p) => in_array($p['method_type'] ?? '', ['credit', 'room_charge'], true));
        [$vehicle, $registration, $odometer, $vehicleFlags] = $this->vehicle($sale, $station, $ext, $credit);
        $first = null;
        // The POS's own review flags of the sale (an unverified actor, a flagged tender, a discount or price override nobody allowed).
        $posFlags = collect((array) ($sale['flags'] ?? []))->map(fn ($f) => is_array($f) ? $f['code'] ?? null : $f)->filter()->reject(fn ($code) => in_array($code, [...Settlements::BENIGN_SALE_FLAGS, ...self::POS_PRICE_FLAGS], true))
            ->map(fn ($code) => ['code' => (string) $code, 'source' => 'pos'])->values()->all();

        foreach ($lines as $line) {
            if (FuelSale::query()->where('sale_line_id', $line['id'])->exists()) {
                continue;
            }

            $product = $products->get($line['item_id']);
            $flags = $saleFlags->all();
            $link = $extLines->get($line['id']);
            $nozzleId = is_array($link) ? ($link['nozzle_id'] ?? null) : null;
            $nozzle = is_string($nozzleId) ? $nozzles->get($nozzleId) : null;
            $row = $nozzle === null ? null : $shiftNozzles->get($nozzle->id);

            if ($nozzleId === null) {
                $flags[] = ['code' => 'nozzle_missing'];
            } elseif ($nozzle === null) {
                $flags[] = ['code' => 'nozzle_unknown'];
            } elseif ($shift !== null && $row === null) {
                $flags[] = ['code' => 'nozzle_not_on_shift'];
            }

            $tank = $nozzle === null ? null : $tanks->get($nozzle->tank_id);

            if ($tank !== null && $tank->product_id !== $product->id) {
                $flags[] = ['code' => 'product_mismatch'];
            }

            $litres = BigDecimal::of((string) $line['qty']);

            if ($litres->getScale() > Litres::SCALE) {
                $flags[] = ['code' => 'litres_rounded'];
            }

            $price = $this->prices->at($station->id, $product->id, $soldAt);

            if ($price === null) {
                $flags[] = ['code' => 'price_missing'];
            } elseif ((string) $price->price_minor !== (string) $line['unit_price_minor']) {
                $flags[] = ['code' => 'price_differs', 'expected_unit_price_minor' => (string) $price->price_minor];
            }

            if ($row !== null && $row->attendant_id !== ($sale['cashier_id'] ?? null)) {
                $flags[] = ['code' => 'attendant_differs'];
            }

            if ((int) ($line['discount_minor'] ?? 0) > 0) {
                $flags[] = ['code' => 'discount'];
            }

            $limit = $vehicle === null ? null : $this->vehicleLimit($vehicle, $product, $litres, $soldAt, $sale);
            $created = FuelSale::create([
                'company_id' => $station->company_id, 'branch_id' => $station->branch_id, 'location_id' => $station->location_id, 'station_id' => $station->id,
                'sale_id' => $sale['id'], 'sale_line_id' => $line['id'],
                'shift_id' => $shift?->id, 'claimed_shift_id' => $claimed, 'shift_nozzle_id' => $row?->id,
                'nozzle_id' => $nozzle?->id, 'tank_id' => $tank?->id, 'product_id' => $product->id,
                'vehicle_id' => $vehicle?->id, 'customer_id' => $sale['customer_id'] ?? null, 'attendant_id' => $row?->attendant_id, 'cashier_id' => $sale['cashier_id'] ?? null,
                'vehicle_registration' => $registration, 'odometer' => $odometer,
                'litres' => (string) Litres::of($litres), 'unit_price_minor' => (string) $line['unit_price_minor'], 'total_minor' => (string) $line['total_minor'],
                'currency' => $sale['currency'], 'sold_at' => $soldAt, 'received_at' => CarbonImmutable::now(),
                'flags' => [...$flags, ...$vehicleFlags, ...($limit === null ? [] : [$limit]), ...$posFlags],
            ]);
            $first ??= $created;
        }

        if ($first === null) {
            return;
        }

        $this->tenders($sale, $lines, $first);

        if ($shift !== null && $shift->status === Shift::CLOSED) {
            $this->settlements->settle($shift, null, null, 'late_sale');
        }
    }

    /** FUEL-12: the fuel share of the sale's payments by tender kind, for the first fuel line's shift and attendant. */
    private function tenders(array $sale, array $lines, FuelSale $first): void
    {
        if (SaleTender::query()->where('sale_id', $sale['id'])->whereNull('refund_id')->exists()) {
            return;
        }

        $fuelTotal = array_reduce($lines, fn (BigInteger $sum, array $l) => $sum->plus((string) $l['total_minor']), BigInteger::zero());
        // FUEL-21: a fuel voucher paid what was really taken off it, not the payment's face value.
        $applied = VoucherRedemption::query()->where('sale_id', $sale['id'])->pluck('applied_minor', 'payment_id');
        $payments = array_map(fn (array $p) => ($p['method_type'] ?? null) === 'fuel_voucher' ? [...$p, 'amount_in_sale_minor' => (string) ($applied[$p['id']] ?? 0)] : $p, (array) ($sale['payments'] ?? []));
        $net = Tenders::net($payments, (string) ($sale['change']['in_sale_minor'] ?? '0'));
        $share = Tenders::fuelShare($net, $fuelTotal, BigInteger::of((string) ($sale['totals']['total_minor'] ?? '0')));

        foreach ($share as $kind => $amount) {
            if (! $amount->isZero()) {
                SaleTender::create([
                    'sale_id' => $sale['id'], 'shift_id' => $first->shift_id, 'attendant_id' => $first->attendant_id,
                    'kind' => $kind, 'amount_minor' => (string) $amount, 'currency' => $sale['currency'],
                ]);
            }
        }
    }

    /**
     * FUEL-20: the vehicle of a fleet or credit sale.
     *
     * @return array{0: ?Vehicle, 1: ?string, 2: ?int, 3: list<array<string, mixed>>}
     */
    private function vehicle(array $sale, Station $station, array $ext, bool $credit): array
    {
        $given = is_array($ext['vehicle'] ?? null) ? $ext['vehicle'] : [];
        $registration = isset($given['registration']) ? $this->registration((string) $given['registration']) : null;
        $odometer = isset($given['odometer']) && is_numeric($given['odometer']) ? (int) $given['odometer'] : null;
        $flags = [];
        $vehicle = null;

        if ($registration === null || $registration === '') {
            $registration = null;

            if ($credit) {
                $flags[] = ['code' => 'vehicle_required'];
            }

            return [null, null, $odometer, $flags];
        }

        $vehicle = Vehicle::query()->where('company_id', $station->company_id)->where('registration', $registration)->first();

        if ($vehicle === null) {
            $flags[] = ['code' => 'vehicle_unknown'];
        } else {
            if ($vehicle->status === Vehicle::BLOCKED || $vehicle->archived_at !== null) {
                $flags[] = ['code' => 'vehicle_blocked'];
            }

            if (($sale['customer_id'] ?? null) !== null && $vehicle->party_id !== $sale['customer_id']) {
                $flags[] = ['code' => 'vehicle_customer_differs'];
            }
        }

        return [$vehicle, $registration, $odometer, $flags];
    }

    /** The vehicle's fuel type and daily litre limit (in the company's time zone). @return ?array<string, mixed> a flag */
    private function vehicleLimit(Vehicle $vehicle, Product $product, BigDecimal $litres, CarbonImmutable $soldAt, array $sale): ?array
    {
        if ($vehicle->fuel_type !== null && $vehicle->fuel_type !== $product->fuel_type) {
            return ['code' => 'vehicle_fuel_differs'];
        }

        if ($vehicle->daily_litre_limit === null) {
            return null;
        }

        $zone = Company::query()->whereKey($sale['company_id'])->value('timezone') ?: 'UTC';
        $start = $soldAt->setTimezone($zone)->startOfDay()->utc();
        $today = FuelSale::query()->where('vehicle_id', $vehicle->id)->whereNull('voided_at')->where('sold_at', '>=', $start)->where('sale_id', '<>', $sale['id'])
            ->selectRaw('coalesce(sum(litres), 0) as total')->value('total');

        return Litres::of((string) $today)->plus($litres)->isGreaterThan(BigDecimal::of((string) $vehicle->daily_litre_limit)) ? ['code' => 'vehicle_limit'] : null;
    }

    private function registration(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));
    }

    /**
     * The fuel lines of the snapshot (items that are fuel products).
     *
     * @return list<array<string, mixed>>
     */
    private function fuelLines(array $sale): array
    {
        $ids = array_values(array_unique(array_filter(array_column((array) ($sale['lines'] ?? []), 'item_id'))));

        if ($ids === []) {
            return [];
        }

        $fuel = Product::query()->whereIn('item_id', $ids)->pluck('item_id')->all();

        return array_values(array_filter((array) $sale['lines'], fn ($l) => in_array($l['item_id'] ?? null, $fuel, true)));
    }
}
