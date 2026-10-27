<?php

namespace Modules\Salon\Tests;

use App\Core\MasterData\Items\Item;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Event;
use Modules\HR\Models\LeaveRequest;
use Modules\HR\Models\LeaveType;
use Modules\HR\Models\RosterShift;
use Modules\HR\Models\Shift;
use Modules\Inventory\Contracts\StockWrittenOff;
use Modules\Inventory\Models\Movement;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Service;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-04, SAL-19: the module works with the core and POS alone; Inventory and HR switch on their integrations when both are subscribed.
class IntegrationsTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function useShampoo(string $qty = '0.5'): Item
    {
        return $this->inTenant(function () use ($qty) {
            $this->haircut->forceFill(['products' => [['item_id' => $this->soap->id, 'qty' => $qty]]])->save();

            return $this->soap;
        });
    }

    public function test_the_products_a_service_uses_leave_stock_when_it_is_finished_and_only_with_inventory(): void
    {
        $this->setUpSalon();
        $this->useShampoo();

        // Without Inventory nothing is asked of it, and finishing is just finishing.
        Event::fake([StockWrittenOff::class]);
        $this->finished('09:00');
        Event::assertNotDispatched(StockWrittenOff::class);

        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('inventory'));
        $made = $this->book('10:00');
        $this->api('POST', "salon/appointments/{$made['id']}/finish", [], $this->annUser)->assertOk();
        $this->api('POST', "salon/appointments/{$made['id']}/finish", [], $this->annUser)->assertOk();
        Event::assertDispatchedTimes(StockWrittenOff::class, 1);
        Event::assertDispatched(StockWrittenOff::class, fn (StockWrittenOff $e) => $e->source === 'salon.consumption' && $e->writeOffId === $made['id'] && $e->locationId === $this->locationA->id
            && $e->lines[0]['item_id'] === $this->soap->id && $e->lines[0]['qty'] === '0.5' && $e->reference === $made['number']);
        $this->assertNotNull($this->appointment($made['id'])->consumed_at);
    }

    public function test_inventory_takes_the_products_out_at_cost_once(): void
    {
        $this->setUpSalon(inventory: true);
        $this->useShampoo('2');
        $made = $this->finished('10:00');
        $this->api('POST', "salon/appointments/{$made['id']}/finish", [], $this->annUser)->assertOk();

        $moves = $this->inTenant(fn () => Movement::query()->where('item_id', $this->soap->id)->get());
        $this->assertCount(1, $moves);
        $this->assertSame(['adjustment_out', 'salon.consumption', '-2.000000'], [$moves[0]->kind, $moves[0]->source_type, (string) $moves[0]->qty]);
    }

    public function test_a_branch_without_a_salon_floor_keeps_the_stock_use_as_an_exception(): void
    {
        $this->setUpSalon(inventory: true);
        $this->useShampoo();
        $this->inTenant(fn () => \Modules\Salon\Models\SalonBranch::query()->update(['location_id' => null]));
        $this->finished('10:00');
        $this->assertSame(0, $this->inTenant(fn () => Movement::query()->where('item_id', $this->soap->id)->count()));
        $this->assertSame(['consumption_no_location'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
    }

    public function test_a_service_uses_stock_items_only_and_the_menu_says_which(): void
    {
        $this->setUpSalon();
        $this->useShampoo('1.5');
        $item = $this->api('GET', 'salon/services?company_id='.$this->acme->id, [], $this->desk)->assertOk()->json('data');
        $haircut = collect($item)->firstWhere('name', 'Haircut');
        $this->assertSame([['item_id' => $this->soap->id, 'item_name' => 'Soap', 'qty' => '1.5']], $haircut['products']);
        $this->api('PUT', "salon/services/{$this->haircut->id}", ['products' => [['item_id' => $this->tipItem->id, 'qty' => '1']]], $this->owner)->assertStatus(422)->assertJsonPath('code', 'item_not_service');
        $this->assertNotNull(Service::class);
    }

    public function test_hr_is_the_source_of_a_linked_staff_members_hours_and_leave(): void
    {
        $this->setUpSalon();
        $this->inTenant(fn () => app(ModuleRegistry::class)->activate('hr'));
        $employee = $this->api('POST', 'hr/employees', ['company_id' => $this->acme->id, 'first_name' => 'Ann', 'last_name' => 'Stylist', 'hire_date' => '2026-01-05', 'branch_id' => $this->branchA->id], $this->owner)->assertCreated()->json('data.id');
        $this->api('PUT', "salon/staff/{$this->ann->id}", ['employee_id' => $employee], $this->owner)->assertOk();

        $this->inTenant(function () use ($employee) {
            $shift = Shift::create(['company_id' => $this->acme->id, 'code' => 'LATE', 'name' => 'Late', 'start_time' => '12:00', 'end_time' => '18:00', 'break_minutes' => 0]);

            foreach ([0, 1] as $day) {
                RosterShift::create([
                    'employee_id' => $employee, 'company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'date' => \Carbon\CarbonImmutable::parse($this->today)->addDays($day)->toDateString(),
                    'shift_id' => $shift->id, 'start_at' => $this->at('12:00', $day), 'end_at' => $this->at('18:00', $day), 'break_minutes' => 0, 'source' => 'manual', 'status' => 'planned',
                ]);
            }

            $type = LeaveType::create(['company_id' => $this->acme->id, 'code' => 'AL', 'name' => 'Annual leave', 'paid' => true, 'accrual' => 'none', 'count_basis' => 'working_days']);
            LeaveRequest::create([
                'number' => 'LV-1', 'employee_id' => $employee, 'company_id' => $this->acme->id, 'leave_type_id' => $type->id, 'start_date' => \Carbon\CarbonImmutable::parse($this->today)->addDay()->toDateString(),
                'end_date' => \Carbon\CarbonImmutable::parse($this->today)->addDay()->toDateString(), 'days' => '1', 'dates' => [['date' => \Carbon\CarbonImmutable::parse($this->today)->addDay()->toDateString(), 'fraction' => '1']],
                'status' => 'approved', 'requested_by' => $this->owner->id,
            ]);
        });

        // The planned shift (12:00 to 18:00) replaces the weekly 09:00 to 17:00: the morning is not hers, and the weekly break (13:00) still holds.
        $book = fn (string $time, int $day = 0) => $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], $time, ['starts_at' => $this->at($time, $day)]), $this->desk);
        $book('10:00')->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $book('13:00')->assertStatus(422)->assertJsonPath('code', 'staff_off');
        $book('17:45')->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $book('14:00')->assertCreated();
        // Approved leave takes the whole day, whatever the roster says; the other stylist is unaffected.
        $book('14:00', 1)->assertStatus(422)->assertJsonPath('code', 'staff_off');
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id]], '14:00', ['starts_at' => $this->at('14:00', 1)]), $this->desk)->assertCreated();
        // What the till pulls already has HR folded in.
        $off = collect($this->inTenant(fn () => app(\Modules\Salon\Sync\Sources\StaffSource::class)->rows(\App\Core\Sync\DeviceScope::of($this->till))))->firstWhere('name', 'Ann')['off'];
        $this->assertContains([$this->at('00:00', 1), $this->at('00:00', 2)], $off);
    }
}
