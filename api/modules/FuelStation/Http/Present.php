<?php

namespace Modules\FuelStation\Http;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Models\Location;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\Dip;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Pump;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\SettlementTender;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\ShiftNozzle;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\StrappingChart;
use Modules\FuelStation\Models\StrappingPoint;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Models\Voucher;
use Modules\FuelStation\Models\VoucherRedemption;
use Modules\FuelStation\Support\Litres;
use Modules\FuelStation\Tanks\BookStock;

/**
 * The module's JSON shapes (docs/modules/fuel-station.md): amounts are minor
 * units as digit strings, litres decimal strings with 3 places, times ISO 8601
 * UTC, references `{id, name}` as entered (single language). One instance per
 * request memoises the names it looks up.
 */
class Present
{
    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private readonly BookStock $book) {}

    private function find(string $kind, ?string $id, callable $load): mixed
    {
        if ($id === null) {
            return null;
        }

        return $this->cache[$kind][$id] ??= $load($id);
    }

    /** @return array{id: string, name: string}|null */
    public function user(?string $id): ?array
    {
        $user = $this->find('user', $id, fn ($i) => User::query()->find($i));

        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }

    /** @return array{id: string, name: string}|null */
    public function location(?string $id): ?array
    {
        $location = $this->find('location', $id, fn ($i) => Location::query()->find($i));

        return $location === null ? null : ['id' => $location->id, 'name' => $location->name];
    }

    /** @return array{id: string, name: string}|null */
    public function party(?string $id): ?array
    {
        $party = $this->find('party', $id, fn ($i) => Party::query()->find($i));

        return $party === null ? null : ['id' => $party->id, 'name' => $party->name];
    }

    /** @return array{id: string, code: string, name: string}|null */
    public function tankRef(?string $id): ?array
    {
        $tank = $this->find('tank', $id, fn ($i) => Tank::query()->find($i));

        return $tank === null ? null : ['id' => $tank->id, 'code' => $tank->code, 'name' => $tank->name];
    }

    public function productRef(?string $id): ?array
    {
        $product = $this->find('product', $id, fn ($i) => Product::query()->find($i));

        if ($product === null) {
            return null;
        }

        $item = $this->find('item', $product->item_id, fn ($i) => Item::query()->find($i));

        return ['id' => $product->id, 'item_id' => $product->item_id, 'name' => $item?->name, 'fuel_type' => $product->fuel_type, 'grade' => $product->grade];
    }

    public function nozzleRef(?string $id): ?array
    {
        $nozzle = $this->find('nozzle', $id, fn ($i) => Nozzle::query()->find($i));

        if ($nozzle === null) {
            return null;
        }

        $pump = $this->find('pump', $nozzle->pump_id, fn ($i) => Pump::query()->find($i));

        return ['id' => $nozzle->id, 'code' => $nozzle->code, 'pump_code' => $pump?->code, 'label' => trim(($pump?->code ?? '').' '.$nozzle->code)];
    }

    public static function at(mixed $value): ?string
    {
        return $value === null ? null : $value->toIso8601String();
    }

    public function settings(Settings $s): array
    {
        return [
            'company_id' => $s->company_id,
            'cash_variance_tolerance_minor' => (string) $s->cash_variance_tolerance_minor,
            'litre_tolerance' => Litres::show($s->litre_tolerance),
            'loss_tolerance_percent' => (string) $s->loss_tolerance_percent,
            'loss_tolerance_litres' => Litres::show($s->loss_tolerance_litres),
            'test_tolerance_litres' => Litres::show($s->test_tolerance_litres),
            'delivery_tolerance_percent' => (string) $s->delivery_tolerance_percent,
        ];
    }

    public function station(Station $s): array
    {
        return [
            'id' => $s->id, 'company_id' => $s->company_id, 'branch_id' => $s->branch_id, 'location' => $this->location($s->location_id),
            'currency' => $s->currency, 'archived_at' => self::at($s->archived_at),
        ];
    }

    public function product(Product $p): array
    {
        return [
            'id' => $p->id, 'company_id' => $p->company_id, 'item_id' => $p->item_id, 'name' => $this->productRef($p->id)['name'] ?? null,
            'fuel_type' => $p->fuel_type, 'grade' => $p->grade, 'archived_at' => self::at($p->archived_at),
        ];
    }

    public function tank(Tank $t, bool $withBook = true): array
    {
        $book = $withBook ? $this->book->of($t) : null;

        return [
            'id' => $t->id, 'station_id' => $t->station_id, 'location' => $this->location($t->location_id), 'code' => $t->code, 'name' => $t->name,
            'product' => $this->productRef($t->product_id), 'capacity_litres' => Litres::show($t->capacity_litres),
            'tolerance_litres' => Litres::show($t->tolerance_litres), 'baseline_litres' => Litres::show($t->baseline_litres), 'baseline_at' => self::at($t->baseline_at),
            'book_litres' => $book === null ? null : (string) $book, 'archived_at' => self::at($t->archived_at),
        ];
    }

    public function chart(StrappingChart $c): array
    {
        return [
            'id' => $c->id, 'tank_id' => $c->tank_id, 'effective_from' => self::at($c->effective_from), 'note' => $c->note, 'created_by' => $this->user($c->created_by),
            'points' => StrappingPoint::query()->where('chart_id', $c->id)->orderBy('dip_cm')->get()->map(fn ($p) => ['dip_cm' => (string) $p->dip_cm, 'litres' => Litres::show($p->litres)])->all(),
        ];
    }

    public function pump(Pump $p): array
    {
        return [
            'id' => $p->id, 'station_id' => $p->station_id, 'location' => $this->location($p->location_id), 'code' => $p->code, 'name' => $p->name,
            'nozzles' => $p->relationLoaded('nozzles') ? $p->nozzles->whereNull('archived_at')->sortBy('code')->map(fn (Nozzle $n) => $this->nozzle($n))->values()->all() : null,
            'archived_at' => self::at($p->archived_at),
        ];
    }

    public function nozzle(Nozzle $n): array
    {
        $tank = Tank::query()->find($n->tank_id);

        return [
            'id' => $n->id, 'station_id' => $n->station_id, 'location' => $this->location($n->location_id), 'pump_id' => $n->pump_id, 'code' => $n->code,
            'label' => $this->nozzleRef($n->id)['label'] ?? $n->code, 'tank' => $this->tankRef($n->tank_id), 'product' => $tank === null ? null : $this->productRef($tank->product_id),
            'last_reading_litres' => Litres::show($n->last_reading_litres), 'last_reading_at' => self::at($n->last_reading_at), 'archived_at' => self::at($n->archived_at),
        ];
    }

    public function price(Price $p): array
    {
        return [
            'id' => $p->id, 'station_id' => $p->station_id, 'location' => $this->location(Station::query()->whereKey($p->station_id)->value('location_id')), 'product' => $this->productRef($p->product_id),
            'price_minor' => (string) $p->price_minor, 'currency' => $p->currency, 'effective_from' => self::at($p->effective_from), 'reference' => $p->reference,
            'entered_by' => $this->user($p->entered_by), 'archived_at' => self::at($p->archived_at), 'created_at' => self::at($p->created_at),
        ];
    }

    public function shiftNozzle(ShiftNozzle $r): array
    {
        return [
            'id' => $r->id, 'nozzle' => $this->nozzleRef($r->nozzle_id), 'attendant' => $this->user($r->attendant_id),
            'opening_litres' => Litres::show($r->opening_litres), 'closing_litres' => Litres::show($r->closing_litres), 'test_litres' => Litres::show($r->test_litres),
            'reset_litres' => Litres::show($r->reset_litres), 'gap_litres' => Litres::show($r->gap_litres), 'meter_litres' => Litres::show($r->meter_litres),
            'recorded_litres' => Litres::show($r->recorded_litres), 'recorded_minor' => (string) $r->recorded_minor, 'unrecorded_litres' => Litres::show($r->unrecorded_litres),
            'price_minor' => $r->price_minor === null ? null : (string) $r->price_minor, 'expected_minor' => (string) $r->expected_minor, 'flags' => $r->flags ?? [],
        ];
    }

    public function shift(Shift $s, bool $detail = false): array
    {
        $data = [
            'id' => $s->id, 'station_id' => $s->station_id, 'location' => $this->location($s->location_id), 'status' => $s->status,
            'opened_by' => $this->user($s->opened_by), 'opened_at' => self::at($s->opened_at), 'closed_by' => $this->user($s->closed_by), 'closed_at' => self::at($s->closed_at),
            'closed_received_at' => self::at($s->closed_received_at), 'flags' => $s->flags ?? [], 'note' => $s->note,
            'settlements_count' => Settlement::query()->where('shift_id', $s->id)->count(),
            'variance_minor' => (string) Settlement::query()->where('shift_id', $s->id)->sum('variance_minor'),
        ];

        if ($detail) {
            $data['nozzles'] = ShiftNozzle::query()->where('shift_id', $s->id)->orderBy('nozzle_id')->get()->map(fn ($r) => $this->shiftNozzle($r))->all();
            $data['settlements'] = Settlement::query()->where('shift_id', $s->id)->orderBy('id')->get()->map(fn ($x) => $this->settlement($x, true))->all();
        }

        return $data;
    }

    public function settlement(Settlement $s, bool $detail = false): array
    {
        $data = [
            'id' => $s->id, 'number' => $s->number, 'shift_id' => $s->shift_id, 'location' => $this->location($s->location_id), 'attendant' => $this->user($s->attendant_id),
            'status' => $s->status, 'version' => $s->version, 'currency' => $s->currency, 'expected_minor' => (string) $s->expected_minor, 'collected_minor' => (string) $s->collected_minor,
            'variance_minor' => (string) $s->variance_minor, 'meter_litres' => Litres::show($s->meter_litres), 'recorded_litres' => Litres::show($s->recorded_litres),
            'litres_variance' => Litres::show($s->litres_variance), 'flags' => $s->flags ?? [], 'decided_by' => $this->user($s->decided_by), 'decided_at' => self::at($s->decided_at),
            'reopened_at' => self::at($s->reopened_at), 'created_at' => self::at($s->created_at),
        ];

        if ($detail) {
            $data['tenders'] = SettlementTender::query()->where('settlement_id', $s->id)->orderBy('kind')->get()->map(fn ($t) => [
                'kind' => $t->kind, 'declared_minor' => $t->declared_minor === null ? null : (string) $t->declared_minor, 'recorded_minor' => (string) $t->recorded_minor, 'collected_minor' => (string) $t->collected_minor,
            ])->all();
            $data['document_type'] = 'fuel.shift_variance';
        }

        return $data;
    }

    public function meterReset(MeterReset $r): array
    {
        return [
            'id' => $r->id, 'nozzle' => $this->nozzleRef($r->nozzle_id), 'location' => $this->location($r->location_id), 'shift_id' => $r->shift_id,
            'reading_before' => Litres::show($r->reading_before), 'reading_after' => Litres::show($r->reading_after), 'reason' => $r->reason, 'status' => $r->status,
            'requested_by' => $this->user($r->requested_by), 'requested_at' => self::at($r->requested_at), 'decided_by' => $this->user($r->decided_by), 'decided_at' => self::at($r->decided_at),
            'actor_verified' => (bool) $r->actor_verified,
        ];
    }

    public function dip(Dip $d): array
    {
        return [
            'id' => $d->id, 'tank' => $this->tankRef($d->tank_id), 'location' => $this->location($d->location_id), 'shift_id' => $d->shift_id, 'kind' => $d->kind, 'source' => $d->source,
            'dip_cm' => (string) $d->dip_cm, 'water_cm' => $d->water_cm === null ? null : (string) $d->water_cm, 'litres' => Litres::show($d->litres), 'chart_id' => $d->chart_id,
            'taken_at' => self::at($d->taken_at), 'taken_by' => $this->user($d->taken_by),
        ];
    }

    public function reconciliation(Reconciliation $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'tank' => $this->tankRef($r->tank_id), 'location' => $this->location($r->location_id), 'dip_id' => $r->dip_id, 'status' => $r->status,
            'previous_litres' => Litres::show($r->previous_litres), 'baseline_at' => self::at($r->baseline_at), 'deliveries_litres' => Litres::show($r->deliveries_litres),
            'sales_litres' => Litres::show($r->sales_litres), 'expected_litres' => Litres::show($r->expected_litres), 'dip_litres' => Litres::show($r->dip_litres),
            'variance_litres' => Litres::show($r->variance_litres), 'tolerance_litres' => Litres::show($r->tolerance_litres), 'flags' => $r->flags ?? [],
            'decided_by' => $this->user($r->decided_by), 'decided_at' => self::at($r->decided_at), 'posted_at' => self::at($r->posted_at), 'created_at' => self::at($r->created_at),
            'document_type' => 'fuel.tank_variance',
        ];
    }

    public function delivery(Delivery $d): array
    {
        return [
            'id' => $d->id, 'number' => $d->number, 'tank' => $this->tankRef($d->tank_id), 'location' => $this->location($d->location_id), 'supplier' => $this->party($d->supplier_id),
            'note_number' => $d->note_number, 'note_litres' => Litres::show($d->note_litres), 'before_litres' => Litres::show($d->before_litres), 'after_litres' => Litres::show($d->after_litres),
            'sold_during_litres' => Litres::show($d->sold_during_litres), 'received_litres' => Litres::show($d->received_litres), 'shortage_litres' => Litres::show($d->shortage_litres),
            'unit_cost_minor' => (string) $d->unit_cost_minor, 'cost_minor' => (string) $d->cost_minor, 'currency' => $d->currency, 'received_at' => self::at($d->received_at),
            'flags' => $d->flags ?? [], 'recorded_by' => $this->user($d->recorded_by),
        ];
    }

    public function vehicle(Vehicle $v): array
    {
        return [
            'id' => $v->id, 'company_id' => $v->company_id, 'customer' => $this->party($v->party_id), 'registration' => $v->registration, 'label' => $v->label,
            'fuel_type' => $v->fuel_type, 'daily_litre_limit' => Litres::show($v->daily_litre_limit), 'status' => $v->status, 'archived_at' => self::at($v->archived_at),
        ];
    }

    public function voucher(Voucher $v, bool $detail = false): array
    {
        $data = [
            'id' => $v->id, 'company_id' => $v->company_id, 'code' => $v->code, 'customer' => $this->party($v->party_id), 'currency' => $v->currency,
            'value_minor' => (string) $v->value_minor, 'balance_minor' => (string) $v->balance_minor, 'fuel_type' => $v->fuel_type, 'expires_on' => $v->expires_on?->toDateString(),
            'status' => $v->status, 'void_reason' => $v->void_reason, 'voided_at' => self::at($v->voided_at), 'issued_by' => $this->user($v->issued_by), 'created_at' => self::at($v->created_at),
        ];

        if ($detail) {
            $data['redemptions'] = VoucherRedemption::query()->where('voucher_id', $v->id)->orderByDesc('redeemed_at')->get()->map(fn ($r) => [
                'sale_id' => $r->sale_id, 'location' => $this->location($r->location_id), 'amount_minor' => (string) $r->amount_minor, 'currency' => $r->currency,
                'redeemed_at' => self::at($r->redeemed_at), 'reversed_at' => self::at($r->reversed_at), 'flags' => $r->flags ?? [],
            ])->all();
        }

        return $data;
    }

    public function sale(FuelSale $s): array
    {
        return [
            'id' => $s->id, 'sale_id' => $s->sale_id, 'sale_line_id' => $s->sale_line_id, 'location' => $this->location($s->location_id), 'shift_id' => $s->shift_id,
            'nozzle' => $this->nozzleRef($s->nozzle_id), 'tank' => $this->tankRef($s->tank_id), 'product' => $this->productRef($s->product_id), 'attendant' => $this->user($s->attendant_id),
            'cashier' => $this->user($s->cashier_id), 'customer' => $this->party($s->customer_id), 'vehicle_registration' => $s->vehicle_registration, 'odometer' => $s->odometer,
            'litres' => Litres::show($s->litres), 'unit_price_minor' => (string) $s->unit_price_minor, 'total_minor' => (string) $s->total_minor, 'currency' => $s->currency,
            'sold_at' => self::at($s->sold_at), 'voided_at' => self::at($s->voided_at), 'flags' => $s->flags ?? [],
        ];
    }

    /** A litres or minor-unit total as a string, for report rows. */
    public static function total(mixed $value, bool $litres = false): string
    {
        return $litres ? (string) Litres::of((string) ($value ?? '0')) : (string) BigDecimal::of((string) ($value ?? '0'))->toScale(0, RoundingMode::HalfUp);
    }
}
