<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\ModuleRegistry;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-02 to HOT-10: settings, room types, rooms, rate plans with seasons and the rate quote.
class SetupApiTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    public function test_settings_default_then_change_and_are_audited(): void
    {
        $this->getJson("/api/v1/companies/{$this->acme->id}/hotel-settings", $this->headersFor())->assertOk()
            ->assertJsonPath('data.check_in_time', '14:00')->assertJsonPath('data.cancel_charge_kind', 'nights')->assertJsonPath('data.room_item_id', $this->roomItem->id);

        $this->putJson("/api/v1/companies/{$this->acme->id}/hotel-settings", ['cancel_free_days' => 3, 'cancel_charge_kind' => 'percent', 'cancel_charge_value' => 50, 'late_checkout_fee_minor' => 150000, 'weekend_days' => [6, 7]], $this->headersFor())
            ->assertOk()->assertJsonPath('data.cancel_free_days', 3)->assertJsonPath('data.late_checkout_fee_minor', '150000')->assertJsonPath('data.weekend_days', [6, 7]);

        $this->putJson("/api/v1/companies/{$this->acme->id}/hotel-settings", ['cancel_charge_kind' => 'percent', 'cancel_charge_value' => 150], $this->headersFor())->assertStatus(422);
        $this->putJson("/api/v1/companies/{$this->acme->id}/hotel-settings", ['check_in_time' => '25:00'], $this->headersFor())->assertStatus(422);
        $this->assertGreaterThan(0, $this->audited('hotel.settings.update'));
    }

    public function test_room_types_and_rooms_are_created_changed_archived_and_audited(): void
    {
        $type = $this->postJson('/api/v1/hotel/room-types', ['company_id' => $this->acme->id, 'code' => 'STD', 'name' => 'Standard', 'base_adults' => 1, 'max_adults' => 2, 'max_occupancy' => 2], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.code', 'STD')->json('data');
        $this->postJson('/api/v1/hotel/room-types', ['company_id' => $this->acme->id, 'code' => 'std', 'name' => 'Duplicate'], $this->headersFor())->assertStatus(422);
        $this->patchJson("/api/v1/hotel/room-types/{$type['id']}", ['name' => 'Standard room', 'max_adults' => 1, 'base_adults' => 2], $this->headersFor())->assertStatus(422);

        $room = $this->postJson('/api/v1/hotel/rooms', ['branch_id' => $this->branchA->id, 'room_type_id' => $type['id'], 'number' => '201', 'floor' => '2', 'features' => ['balcony', 'sea_view']], $this->headersFor())
            ->assertCreated()->assertJsonPath('data.status', 'vacant_clean')->assertJsonPath('data.features', ['balcony', 'sea_view'])->json('data');
        $this->postJson('/api/v1/hotel/rooms', ['branch_id' => $this->branchA->id, 'room_type_id' => $type['id'], 'number' => '201'], $this->headersFor())->assertStatus(422);
        $this->patchJson("/api/v1/hotel/rooms/{$room['id']}", ['floor' => '3'], $this->headersFor())->assertOk()->assertJsonPath('data.floor', '3');
        $this->getJson('/api/v1/hotel/rooms?floor=3', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');

        // A type with rooms cannot be archived; once its rooms are, it can.
        $this->postJson("/api/v1/hotel/room-types/{$type['id']}/archive", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'room_type_in_use');
        $this->postJson("/api/v1/hotel/rooms/{$room['id']}/archive", [], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/hotel/room-types/{$type['id']}/archive", [], $this->headersFor())->assertOk();
        $this->getJson('/api/v1/hotel/room-types', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');

        foreach (['hotel.room_type.create', 'hotel.room.create', 'hotel.room.update', 'hotel.room.archive'] as $action) {
            $this->assertGreaterThan(0, $this->audited($action));
        }
    }

    public function test_a_room_status_moves_between_clean_dirty_and_out_of_order(): void
    {
        $room = $this->rooms['101'];
        $this->postJson("/api/v1/hotel/rooms/{$room->id}/status", ['status' => 'vacant_dirty'], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'vacant_dirty');
        $this->postJson("/api/v1/hotel/rooms/{$room->id}/status", ['status' => 'out_of_order'], $this->headersFor())->assertStatus(422);
        $this->postJson("/api/v1/hotel/rooms/{$room->id}/status", ['status' => 'out_of_order', 'reason' => 'Leaking tap', 'until' => $this->day(5)], $this->headersFor())
            ->assertOk()->assertJsonPath('data.status', 'out_of_order')->assertJsonPath('data.out_of_order.reason', 'Leaking tap')->assertJsonPath('data.out_of_order.until', $this->day(5));
        $this->postJson("/api/v1/hotel/rooms/{$room->id}/status", ['status' => 'vacant_clean'], $this->headersFor())->assertOk()->assertJsonPath('data.out_of_order', null);
        $this->assertSame(Room::VACANT_CLEAN, $this->inTenant(fn () => Room::find($room->id)->status));
    }

    public function test_a_rate_plan_with_seasons_quotes_weekday_weekend_and_extra_person_rates(): void
    {
        $plan = $this->postJson('/api/v1/hotel/rate-plans', [
            'room_type_id' => $this->deluxe->id, 'code' => 'HB', 'name' => 'Half board', 'board' => 'half_board', 'currency' => 'KES',
            'weekday_rate_minor' => 1500000, 'weekend_rate_minor' => 1800000, 'extra_adult_minor' => 300000, 'child_rate_minor' => 150000, 'free_children' => 0,
            'seasons' => [['name' => 'Peak', 'starts_on' => $this->day(2), 'ends_on' => $this->day(3), 'weekday_rate_minor' => 2500000, 'weekend_rate_minor' => 2800000, 'priority' => 5]],
        ], $this->headersFor())->assertCreated()->assertJsonCount(1, 'data.seasons')->json('data');

        // Wed 3 Mar to Mon 8 Mar: Wed, Thu base weekday; Fri, Sat season (Fri is a weekend day: 28,000.00; Sat likewise); Sun weekday base (peak ended).
        // Peak is Fri 5 and Sat 6 here (day(2) = Fri). Two adults: no extras.
        $quote = $this->getJson('/api/v1/hotel/rate-quote?rate_plan_id='.$plan['id'].'&arrival_on='.$this->day(0).'&departure_on='.$this->day(5).'&adults=2', $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['1500000', '1500000', '2800000', '2800000', '1500000'], array_column($quote['nights'], 'rate_minor'));
        $this->assertSame([null, null, 'Peak', 'Peak', null], array_column($quote['nights'], 'season'));
        $this->assertSame('10100000', $quote['total_minor']);

        // A third adult pays the extra adult rate and a child the child rate each night (HOT-09).
        $withExtras = $this->getJson('/api/v1/hotel/rate-quote?rate_plan_id='.$plan['id'].'&arrival_on='.$this->day(0).'&departure_on='.$this->day(1).'&adults=3', $this->headersFor())->assertOk()->json('data');
        $this->assertSame('1800000', $withExtras['total_minor']);
        $this->getJson('/api/v1/hotel/rate-quote?rate_plan_id='.$plan['id'].'&arrival_on='.$this->day(0).'&departure_on='.$this->day(1).'&adults=3&children=1', $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'occupancy_exceeded');
        $this->getJson('/api/v1/hotel/rate-quote?rate_plan_id='.$plan['id'].'&arrival_on='.$this->day(1).'&departure_on='.$this->day(1), $this->headersFor())->assertStatus(422);

        // The tax estimate uses the item's tax code (test rate 12.5 %, rates included in the plan price when it says so).
        $inclusive = $this->getJson('/api/v1/hotel/rate-quote?rate_plan_id='.$this->bb->id.'&arrival_on='.$this->day(0).'&departure_on='.$this->day(1).'&adults=2', $this->headersFor())->assertOk()->json('data');
        $this->assertSame('1000000', $inclusive['total_minor']);
        $this->assertSame('1000000', $inclusive['estimate']['gross_minor']);
        $this->assertSame('111111', $inclusive['estimate']['tax_minor']);
    }

    public function test_a_rate_plan_update_replaces_seasons_and_archiving_is_refused_while_booked(): void
    {
        $this->patchJson("/api/v1/hotel/rate-plans/{$this->bb->id}", ['seasons' => [['name' => 'Low', 'starts_on' => $this->day(10), 'ends_on' => $this->day(20), 'weekday_rate_minor' => 800000]]], $this->headersFor())
            ->assertOk()->assertJsonPath('data.seasons.0.name', 'Low');
        $this->patchJson("/api/v1/hotel/rate-plans/{$this->bb->id}", ['seasons' => []], $this->headersFor())->assertOk()->assertJsonCount(0, 'data.seasons');
        $this->assertGreaterThan(0, $this->audited('hotel.rate_plan.seasons'));

        $this->book(2);
        $this->postJson("/api/v1/hotel/rate-plans/{$this->bb->id}/archive", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'rate_plan_in_use');
    }

    public function test_the_module_is_closed_while_it_is_off(): void
    {
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('hotel'));
        $this->getJson('/api/v1/hotel/rooms', $this->headersFor())->assertForbidden()->assertJsonPath('code', 'module_inactive');
    }
}
