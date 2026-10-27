<?php

namespace Modules\FuelStation\Tests\Concerns;

use App\Core\Audit\AuditEntry;
use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Items\Uom;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Pump;
use Modules\FuelStation\Models\Settings;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\StrappingChart;
use Modules\FuelStation\Models\StrappingPoint;
use Modules\FuelStation\Models\Tank;
use Modules\POS\Tests\Concerns\BuildsPos;

/**
 * A tenant with the POS and Fuel station modules active (BuildsPos' Acme in KES, a paired till at location A): location A is a station
 * with a petrol product (a litre item, price KES 180.50 per litre), one 10,000 L tank with a chart (0 cm = 0 L, 50 cm = 5,000 L,
 * 100 cm = 10,000 L), pump P1 with nozzles A and B, a forecourt attendant, a shift supervisor and a station manager at the location.
 * Rates and amounts are synthetic test figures, never real prices.
 */
trait BuildsFuel
{
    use BuildsPos;

    protected Uom $litre;

    protected Item $petrol;

    protected Station $station;

    protected Product $product;

    protected Tank $tank;

    protected Pump $pump;

    protected Nozzle $nozzleA;

    protected Nozzle $nozzleB;

    protected User $attendant;

    protected User $attendant2;

    protected User $supervisor;

    protected User $manager;

    protected PaymentMethod $voucherMethod;

    protected function setUpFuel(): void
    {
        $this->setUpPos();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('fuel');
            $this->litre = Uom::create(['code' => 'L', 'name' => 'Litre', 'kind' => 'volume']);
            $this->petrol = Item::create(['code' => 'PMS', 'name' => 'Petrol', 'type' => 'stock', 'base_uom_id' => $this->litre->id, 'tax_category_id' => $this->goods->id]);
            $this->station = Station::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'currency' => 'KES']);
            $this->product = Product::create(['company_id' => $this->acme->id, 'item_id' => $this->petrol->id, 'fuel_type' => 'petrol', 'grade' => 'Super']);
            $this->tank = Tank::create([
                'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'station_id' => $this->station->id,
                'product_id' => $this->product->id, 'code' => 'T1', 'name' => 'Tank 1', 'capacity_litres' => '10000.000',
            ]);
            $chart = StrappingChart::create(['company_id' => $this->acme->id, 'tank_id' => $this->tank->id, 'effective_from' => CarbonImmutable::parse('2020-01-01'), 'created_by' => $this->owner->id]);

            foreach ([['0.00', '0.000'], ['50.00', '5000.000'], ['100.00', '10000.000']] as [$cm, $litres]) {
                StrappingPoint::create(['chart_id' => $chart->id, 'dip_cm' => $cm, 'litres' => $litres]);
            }

            $place = ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'station_id' => $this->station->id];
            $this->pump = Pump::create([...$place, 'code' => 'P1', 'name' => 'Pump 1']);
            $this->nozzleA = Nozzle::create([...$place, 'pump_id' => $this->pump->id, 'tank_id' => $this->tank->id, 'code' => 'A']);
            $this->nozzleB = Nozzle::create([...$place, 'pump_id' => $this->pump->id, 'tank_id' => $this->tank->id, 'code' => 'B']);
            Price::create(['company_id' => $this->acme->id, 'station_id' => $this->station->id, 'product_id' => $this->product->id, 'price_minor' => 18050, 'currency' => 'KES', 'effective_from' => CarbonImmutable::parse('2020-01-01'), 'entered_by' => $this->owner->id]);
            $this->voucherMethod = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'fuel_voucher', 'name' => 'Fuel voucher', 'currency' => 'KES', 'active' => true, 'position' => 8]);
        });

        $scope = Scope::location($this->locationA->id);
        $this->attendant = $this->userWith('forecourt_attendant', $scope);
        $this->attendant2 = $this->userWith('forecourt_attendant', $scope);
        $this->supervisor = $this->userWith('shift_supervisor', $scope);
        $this->manager = $this->userWith('station_manager', $scope);
    }

    protected function audited(string $action): int
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('action', $action)->count());
    }

    /** The body of a device shift upload: opened now-1h at the given readings (nozzle A and B), optionally closed. */
    protected function fuelShiftBody(?string $id = null, array $readings = ['A' => '1000.000', 'B' => '5000.000'], ?array $closing = null, ?User $opener = null): array
    {
        $opener ??= $this->supervisor;
        $nozzles = ['A' => $this->nozzleA, 'B' => $this->nozzleB];
        $body = [
            'id' => $id ?? $this->id(),
            'opening' => [
                'opened_by_id' => $opener->id,
                'opened_at' => now()->subHour()->toIso8601String(),
                'actor_proof' => $this->actorProof($opener->id),
                'nozzles' => collect($readings)->map(fn ($reading, $code) => ['nozzle_id' => $nozzles[$code]->id, 'attendant_id' => $code === 'A' ? $this->attendant->id : $this->attendant2->id, 'reading' => $reading])->values()->all(),
            ],
        ];

        if ($closing !== null) {
            $body['closing'] = $closing;
        }

        return $body;
    }

    /** A closing for the shift: closing readings per nozzle code and cash counted per attendant (A: attendant, B: attendant 2). */
    protected function fuelClosing(array $readings, array $cash, array $over = [], ?User $closer = null): array
    {
        $closer ??= $this->supervisor;
        $nozzles = ['A' => [$this->nozzleA, $this->attendant], 'B' => [$this->nozzleB, $this->attendant2]];

        return array_replace([
            'closed_by_id' => $closer->id,
            'closed_at' => now()->subMinutes(2)->toIso8601String(),
            'actor_proof' => $this->actorProof($closer->id),
            'nozzles' => collect($readings)->map(fn ($reading, $code) => ['nozzle_id' => $nozzles[$code][0]->id, 'reading' => $reading])->values()->all(),
            'tenders' => collect($cash)->map(fn ($amount, $code) => ['attendant_id' => $nozzles[$code][1]->id, 'tenders' => [['kind' => 'cash', 'amount_minor' => (string) $amount]]])->values()->all(),
        ], $over);
    }

    protected function uploadShifts(array $shifts, ?string $token = null)
    {
        return $this->postJson('/api/v1/fuel/device/shifts', ['shifts' => $shifts], $this->tillHeaders($token));
    }

    /** A POS sale of $litres of petrol (at KES 180.50, tax included at the test rate) at nozzle A of the fuel shift $shiftId (POS cash shift $posShift), paid in cash by default. */
    protected function fuelSaleBody(string $shiftId, string $posShift, string $litres = '10.000', int $seq = 1, array $over = [], string $nozzle = 'A'): array
    {
        $total = (int) round(18050 * (float) $litres);
        $tax = (int) round($total * 12.5 / 112.5);
        $line = $this->line(['item_id' => $this->petrol->id, 'uom_id' => $this->litre->id, 'qty' => $litres, 'unit_price_minor' => '18050', 'list_price_minor' => '18050', 'tax_minor' => (string) $tax, 'total_minor' => (string) $total]);
        $extensions = $over['extensions'] ?? ['fuel' => ['shift_id' => $shiftId, 'lines' => [['line_id' => $line['id'], 'nozzle_id' => ($nozzle === 'A' ? $this->nozzleA : $this->nozzleB)->id]]]];

        return $this->saleBody($posShift, $seq, [...$over, 'lines' => [$line], 'extensions' => $extensions, 'cashier_id' => $this->attendant->id, 'actor_proof' => $this->actorProof($this->attendant->id)]);
    }

    /** A POS cash shift so sales can be uploaded; returns its id. */
    protected function posShift(): string
    {
        $this->ranges()->assertOk();

        return $this->openShift(null, $this->owner->id);
    }

    /** FUEL-03: the company allows this many litres between the meters and the sales rung up before a shift needs approval. */
    protected function allowLitres(string $litres): void
    {
        $this->inTenant(fn () => Settings::query()->updateOrCreate(['company_id' => $this->acme->id], ['litre_tolerance' => $litres]));
    }
}
