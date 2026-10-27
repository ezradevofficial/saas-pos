<?php

namespace Modules\FuelStation\Tests;

use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use Brick\Math\BigInteger;
use Modules\FuelStation\Models\FuelSale;
use Modules\FuelStation\Models\SaleTender;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Models\Voucher;
use Modules\FuelStation\Models\VoucherRedemption;
use Modules\FuelStation\Shifts\Meters;
use Modules\FuelStation\Shifts\Tenders;
use Modules\FuelStation\Tests\Concerns\BuildsFuel;
use Modules\POS\Models\Sale;
use Modules\POS\Tests\Concerns\SimulatesDevice;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// FUEL-12, FUEL-18 to FUEL-21, FUEL-29, FUEL-34: fuel sales on the POS path, fleet, vouchers, the sync entities and the reports.
class SalesTest extends TestCase
{
    use BuildsFuel, RefreshTenantDatabase, SimulatesDevice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFuel();
    }

    private function openFuelShift(): string
    {
        $id = $this->id();
        $this->uploadShifts([$this->fuelShiftBody($id)])->assertOk();

        return $id;
    }

    public function test_a_fuel_sale_joins_the_shift_and_nozzle_and_is_flagged_when_it_differs(): void
    {
        $shift = $this->openFuelShift();
        $pos = $this->posShift();
        $this->upload([$this->fuelSaleBody($shift, $pos, '12.345', 1)])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $other = $this->fuelSaleBody($shift, $pos, '1.000', 2);
        $other['lines'][0]['unit_price_minor'] = '17000';
        $other['lines'][0]['list_price_minor'] = '17000';
        $other['lines'][0]['total_minor'] = '17000';
        $other['lines'][0]['tax_minor'] = '1889';
        $other['totals'] = ['subtotal_minor' => '17000', 'discount_minor' => '0', 'tax_minor' => '1889', 'total_minor' => '17000'];
        $other['payments'][0]['amount_minor'] = $other['payments'][0]['amount_in_sale_minor'] = '17000';
        $this->upload([$other])->assertOk();

        $this->inTenant(function () use ($shift) {
            $sales = FuelSale::query()->orderBy('litres')->get();
            $this->assertCount(2, $sales);
            $this->assertSame('12.345', $sales[1]->litres);
            $this->assertSame($shift, $sales[1]->shift_id);
            $this->assertSame($this->nozzleA->id, $sales[1]->nozzle_id);
            $this->assertSame($this->tank->id, $sales[1]->tank_id);
            $this->assertSame($this->attendant->id, $sales[1]->attendant_id);
            $this->assertContains('price_differs', array_column($sales[0]->flags, 'code'));
            $this->assertNotContains('price_differs', array_column($sales[1]->flags, 'code'));
        });
        $this->getJson('/api/v1/fuel/sales?flagged=1', $this->headersFor($this->supervisor))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_fleet_credit_sales_need_a_known_unblocked_vehicle_and_respect_the_daily_limit(): void
    {
        $shift = $this->openFuelShift();
        $pos = $this->posShift();
        $customer = $this->customer($this->acme->id);
        $credit = $this->inTenant(fn () => PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'credit', 'name' => 'Account', 'currency' => 'KES', 'active' => true, 'position' => 7]));
        $this->inTenant(fn () => Vehicle::create(['company_id' => $this->acme->id, 'party_id' => $customer->id, 'registration' => 'KDD123A', 'daily_litre_limit' => '15', 'status' => 'active']));
        $this->inTenant(fn () => Vehicle::create(['company_id' => $this->acme->id, 'party_id' => $customer->id, 'registration' => 'KBB999B', 'status' => 'blocked']));

        $send = function (int $seq, string $litres, ?array $vehicle) use ($shift, $pos, $customer, $credit) {
            $body = $this->fuelSaleBody($shift, $pos, $litres, $seq);
            $total = $body['totals']['total_minor'];
            $body['customer_id'] = $customer->id;
            $body['payments'][0]['payment_method_id'] = $credit->id;
            $body['extensions']['fuel']['vehicle'] = $vehicle;
            $this->upload([$body])->assertOk();

            return $this->inTenant(fn () => array_column(FuelSale::query()->where('sale_id', $body['id'])->firstOrFail()->flags, 'code'));
        };

        $this->assertContains('vehicle_required', $send(1, '5.000', null));
        $this->assertContains('vehicle_unknown', $send(2, '5.000', ['registration' => 'ZZZ 1']));
        $this->assertContains('vehicle_blocked', $send(3, '5.000', ['registration' => 'kbb 999b']));
        $this->assertNotContains('vehicle_limit', $send(4, '10.000', ['registration' => 'KDD 123A', 'odometer' => 120500]));
        $this->assertContains('vehicle_limit', $send(5, '6.000', ['registration' => 'KDD 123A']));

        // The fleet statement and the credit tender kind.
        $this->getJson('/api/v1/fuel/reports/fleet-statement', $this->headersFor($this->manager))->assertOk()->assertJsonPath('totals.0.sales', 5)->assertJsonPath('totals.0.litres', '31.000');
        $this->inTenant(fn () => $this->assertSame(5, SaleTender::query()->where('kind', 'credit')->count()));
    }

    public function test_a_voucher_pays_a_sale_and_redeems_once(): void
    {
        $shift = $this->openFuelShift();
        $pos = $this->posShift();
        $voucher = $this->inTenant(fn () => Voucher::create(['company_id' => $this->acme->id, 'code' => 'FUEL100', 'currency' => 'KES', 'value_minor' => 500000, 'balance_minor' => 500000, 'issued_by' => $this->owner->id]));

        $body = $this->fuelSaleBody($shift, $pos, '10.000', 1);
        $body['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $body['payments'][0]['tender'] = ['code' => 'fuel100'];
        $this->upload([$body])->assertOk();
        $this->upload([$body])->assertOk();
        $this->inTenant(function () use ($voucher) {
            $this->assertSame(1, VoucherRedemption::query()->count());
            $this->assertSame(500000 - 180500, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);
            $this->assertSame(180500, (int) SaleTender::query()->where('kind', 'voucher')->sum('amount_minor'));
        });

        // An unknown code is flagged on the sale, never refused (the fuel is gone).
        $bad = $this->fuelSaleBody($shift, $pos, '1.000', 2);
        $bad['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $bad['payments'][0]['tender'] = ['code' => 'NOPE'];
        $this->upload([$bad])->assertOk();
        $this->assertContains('tender_flagged', array_column($this->inTenant(fn () => Sale::query()->findOrFail($bad['id'])->flags), 'code'));

        // A voucher the sale overdraws is recorded in full, flagged and emptied.
        $this->inTenant(fn () => Voucher::query()->whereKey($voucher->id)->update(['balance_minor' => 1000]));
        $over = $this->fuelSaleBody($shift, $pos, '1.000', 3);
        $over['payments'][0]['payment_method_id'] = $this->voucherMethod->id;
        $over['payments'][0]['tender'] = ['code' => 'FUEL100'];
        $this->upload([$over])->assertOk();
        $this->inTenant(function () use ($voucher, $over) {
            $this->assertSame(0, (int) Voucher::query()->findOrFail($voucher->id)->balance_minor);
            $this->assertSame('voucher_overdrawn', VoucherRedemption::query()->where('sale_id', $over['id'])->first()->flags[0]['code']);
        });
    }

    public function test_the_till_pulls_the_stations_nozzles_prices_tanks_vehicles_and_vouchers(): void
    {
        $device = $this->pairDevice();
        $entities = $this->bootstrapDevice($device);

        foreach (['fuel_station', 'fuel_nozzles', 'fuel_prices', 'fuel_tanks', 'fuel_vehicles', 'fuel_vouchers'] as $key) {
            $this->assertContains($key, $entities);
        }

        $pulled = $this->pullAllEntities($device, ['fuel_station', 'fuel_nozzles', 'fuel_prices', 'fuel_tanks']);
        $rows = fn (string $key) => $this->applyPages([], $pulled['pages'][$key]);
        $this->assertCount(1, $rows('fuel_station'));
        $this->assertCount(2, $rows('fuel_nozzles'));
        $this->assertSame('18050', array_values($rows('fuel_prices'))[0]['price_minor']);
        $tank = array_values($rows('fuel_tanks'))[0];
        $this->assertSame([['0.00', '0.000'], ['50.00', '5000.000'], ['100.00', '10000.000']], $tank['strapping'][0]['points']);

        // Staff rows carry the fuel permissions of the people at the till.
        $this->assertContains('fuel.sale.create', collect($this->applyPages([], $this->pullAllEntities($device, ['staff'])['pages']['staff']))->firstWhere('id', $this->attendant->id)['permissions'] ?? []);
    }

    public function test_a_tenant_without_the_module_gets_nothing_and_its_routes_are_closed(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('fuel'));
        $this->getJson('/api/v1/fuel/tanks', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
        $this->uploadShifts([$this->fuelShiftBody($this->id())])->assertForbidden();
        $this->assertNotContains('fuel_nozzles', $this->bootstrapDevice($this->pairDevice()));
    }

    public function test_the_meter_maths_and_the_tender_shares_are_exact(): void
    {
        $this->assertSame('95.000', (string) Meters::litres('1000', '1100.000', '5'));
        $this->assertSame('70.000', (string) Meters::litres('1000', '20', '0', '1050'));
        $this->assertSame('0.001', (string) Meters::litres('0.001', '0.002'));
        $this->assertSame('0.000', (string) Meters::unrecorded(Meters::litres('10', '10.5'), Meters::litres('0', '12')));

        // 1,000 paid by cash 600 and M-Pesa 600 for a 1,000 sale: 200 change comes out of cash.
        $net = Tenders::net([['method_type' => 'cash', 'amount_in_sale_minor' => '600'], ['method_type' => 'mobile_money', 'amount_in_sale_minor' => '600']], '200');
        $this->assertSame(['cash' => '400', 'mobile_money' => '600'], array_map('strval', $net));
        // A mixed sale of 1,000 with 300 of fuel: 30 % of each tender, the difference on the largest, summing exactly to 300.
        $share = Tenders::fuelShare($net, BigInteger::of(300), BigInteger::of(1000));
        $this->assertSame(['cash' => '120', 'mobile_money' => '180'], array_map('strval', $share));
        $odd = Tenders::fuelShare(['cash' => BigInteger::of(333), 'card' => BigInteger::of(334)], BigInteger::of(100), BigInteger::of(667));
        $this->assertSame('100', (string) $odd['cash']->plus($odd['card']));
        $this->assertSame('credit', Tenders::kindOf('room_charge'));
        $this->assertSame('voucher', Tenders::kindOf('fuel_voucher'));
    }

    public function test_reports_cover_shifts_attendants_meters_tanks_and_products(): void
    {
        $this->allowLitres('1000');
        $id = $this->id();
        $closing = $this->fuelClosing(['A' => '1100.000', 'B' => '5000.000'], ['A' => 1805000, 'B' => 0]);
        $this->uploadShifts([$this->fuelShiftBody($id, closing: $closing)])->assertOk();
        $this->upload([$this->fuelSaleBody($id, $this->posShift(), '10.000')])->assertOk();

        $h = $this->headersFor($this->manager);
        $this->getJson('/api/v1/fuel/reports/shift-summary', $h)->assertOk()->assertJsonPath('data.0.litres', '100.000')->assertJsonPath('totals.0.currency', 'KES');
        $this->getJson('/api/v1/fuel/reports/attendant-variance', $h)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/fuel/reports/nozzle-meter-history', $h)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.opening_litres', '1000.000');
        $this->getJson('/api/v1/fuel/reports/tank-reconciliation', $h)->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/fuel/reports/sales-by-product', $h)->assertOk()->assertJsonPath('data.0.litres', '10.000')->assertJsonPath('data.0.total_minor', '180500');
        $this->getJson('/api/v1/fuel/reports/fleet-statement', $h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/fuel/reports/shift-summary', $this->headersFor($this->attendant))->assertForbidden();
    }
}
