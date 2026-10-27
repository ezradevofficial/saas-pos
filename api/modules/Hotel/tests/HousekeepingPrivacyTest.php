<?php

namespace Modules\Hotel\Tests;

use App\Core\Privacy\SubjectData;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use App\Core\Sync\SyncSources;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-28, HOT-29, HOT-16, HOT-35, NFR-08: the housekeeping board and a housekeeper's phone, guest data under the privacy module, and the sync entity.
class HousekeepingPrivacyTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    public function test_a_housekeeper_works_a_task_from_their_phone_and_the_room_becomes_clean(): void
    {
        $stay = $this->checkedIn(1, '101');
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", [], $this->headersFor())->assertOk();
        $housekeeper = $this->userWith('housekeeping', Scope::branch($this->branchA->id));
        $h = $this->headersFor($housekeeper);

        $mine = $this->getJson('/api/v1/hotel/housekeeping/my-tasks', $h)->assertOk()->json('data');
        $this->assertCount(1, $mine);
        $this->assertSame(['checkout_clean', '101', 'vacant_dirty'], [$mine[0]['kind'], $mine[0]['room']['number'], $mine[0]['room']['status']]);
        // No guest data on the phone, ever.
        $this->assertStringNotContainsString('Amina', json_encode($mine));

        $id = $mine[0]['id'];
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/start", [], $h)->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/start", [], $h)->assertStatus(409);
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/finish", [], $h)->assertOk()->assertJsonPath('data.status', 'done');
        $this->assertSame(Room::VACANT_CLEAN, $this->inTenant(fn () => Room::find($this->rooms['101']->id)->status));
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/finish", [], $h)->assertStatus(409)->assertJsonPath('code', 'task_closed');
        $this->getJson('/api/v1/hotel/housekeeping/my-tasks', $h)->assertOk()->assertJsonCount(0, 'data');

        // The supervisor inspects it; a housekeeper cannot.
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/inspect", [], $h)->assertForbidden();
        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$id}/inspect", [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'inspected');
        $this->assertGreaterThan(0, $this->audited('hotel.housekeeping.finish'));
    }

    public function test_the_board_shows_rooms_by_floor_with_status_tasks_and_assignment(): void
    {
        $this->checkedIn(1, '101');
        $leaving = $this->checkedIn(1, '102');
        $this->postJson("/api/v1/hotel/stays/{$leaving['id']}/check-out", [], $this->headersFor())->assertOk();
        $other = $this->userWith('housekeeping', Scope::branch($this->branchA->id));
        $task = $this->inTenant(fn () => HousekeepingTask::query()->sole()->id);

        $this->postJson("/api/v1/hotel/housekeeping/tasks/{$task}/assign", ['assigned_to' => $other->id], $this->headersFor())->assertOk()->assertJsonPath('data.assigned_to.id', $other->id);
        $board = $this->getJson('/api/v1/hotel/housekeeping/board?branch_id='.$this->branchA->id, $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['occupied' => 1, 'vacant_dirty' => 1, 'vacant_clean' => 1], $board['counts']);
        $this->assertSame('vacant_dirty', collect($board['rooms'])->firstWhere('number', '102')['status']);
        $this->assertSame($other->id, collect($board['rooms'])->firstWhere('number', '102')['tasks'][0]['assigned_to']['id']);

        // A task made by hand is never doubled for the same room, kind and day.
        $first = $this->postJson('/api/v1/hotel/housekeeping/tasks', ['room_id' => $this->rooms['103']->id, 'kind' => 'deep_clean'], $this->headersFor())->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/hotel/housekeeping/tasks', ['room_id' => $this->rooms['103']->id, 'kind' => 'deep_clean'], $this->headersFor())->assertCreated()->json('data.id');
        $this->assertSame($first, $second);
        $this->getJson('/api/v1/hotel/reports/housekeeping?branch='.$this->branchA->id, $this->headersFor())->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_guest_register_follows_the_privacy_module(): void
    {
        $this->book(1);
        $report = $this->inTenant(fn () => app(SubjectData::class)->anonymise('customer', $this->guest->party_id, 'Erased'));

        // Guests with stays are kept for the register (retention to confirm): the identity stays, the contact details go.
        $guest = $this->inTenant(fn () => Guest::find($this->guest->id));
        $this->assertSame(['12345678', 'national_id'], [$guest->id_number, $guest->id_type]);
        $this->assertNull($guest->phone);
        $this->assertContains('hotel_guests', array_column($report['kept'], 'table'));

        // A guest who never stayed is erased fully.
        $clean = $this->inTenant(fn () => Guest::create(['company_id' => $this->acme->id, 'party_id' => $this->customer($this->acme->id)->id, 'name' => 'Never Came', 'id_number' => 'ZZ99', 'id_type' => 'passport', 'phone' => '+254711000000']));
        $this->inTenant(fn () => app(SubjectData::class)->anonymise('customer', $clean->party_id, 'Erased'));
        $gone = $this->inTenant(fn () => Guest::find($clean->id));
        $this->assertSame(['Erased', null, null], [$gone->name, $gone->id_number, $gone->phone]);
    }

    public function test_the_in_house_entity_is_offered_only_while_the_module_is_on(): void
    {
        $this->inTenant(function () {
            $this->assertContains('hotel_in_house', array_column(app(SyncSources::class)->offered(), 'key'));
            app(ModuleRegistry::class)->deactivate('hotel');
            $this->assertNotContains('hotel_in_house', array_column(app(SyncSources::class)->offered(), 'key'));
        });
    }
}
