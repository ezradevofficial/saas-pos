<?php

namespace Modules\Salon\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\DeviceScope;
use Illuminate\Testing\TestResponse;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Sync\Sources\PackagesSource;
use Modules\Salon\Sync\Sources\QueueSource;
use Modules\Salon\Sync\Sources\ScheduleSource;
use Modules\Salon\Sync\Sources\ServicesSource;
use Modules\Salon\Sync\Sources\SettingsSource;
use Modules\Salon\Sync\Sources\StaffSource;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-12, SAL-13, SAL-29, NFR-04: what the till sends (idempotent, proven, checked against its place) and what it pulls.
class DeviceSyncTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function sync(array $records, ?string $token = null): TestResponse
    {
        return $this->postJson('/api/v1/salon/device/sync', $records, $this->tillHeaders($token));
    }

    /** @return array<string, mixed> a booking made at the till by the desk */
    private function record(string $time = '10:00', array $overrides = []): array
    {
        return array_replace([
            'id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'salon_client_id' => $this->client->id, 'starts_at' => $this->at($time),
            'lines' => [['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]],
        ], $overrides);
    }

    private function event(string $appointmentId, string $type, $user = null, array $overrides = []): array
    {
        $user ??= $this->annUser;

        return array_replace(['id' => $this->id(), 'appointment_id' => $appointmentId, 'type' => $type, 'user_id' => $user->id, 'actor_proof' => $this->actorProof($user->id), 'at' => now()->toIso8601String()], $overrides);
    }

    public function test_an_appointment_made_at_the_till_is_booked_once_however_often_it_is_sent(): void
    {
        $this->setUpSalon();
        $record = $this->record();
        $first = $this->sync(['appointments' => [$record]])->assertOk()->json('results.appointments.0');
        $this->assertSame(['stored', 'booked'], [$first['status'], $first['appointment_status']]);
        $again = $this->sync(['appointments' => [$record]])->assertOk()->json('results.appointments.0');
        $this->assertSame([$first['number'], 1], [$again['number'], $this->inTenant(fn () => Appointment::query()->count())]);
        $row = $this->appointment($record['id']);
        $this->assertSame(['device', $this->till->id, $this->locationA->id, $this->desk->id], [$row->source, $row->device_id, $row->location_id, $row->created_by]);

        // The same time again is a clash (the server decides, the till is told).
        $clash = $this->sync(['appointments' => [$this->record('10:00'), $this->record('11:00')]])->assertOk()->json('results.appointments');
        $this->assertSame(['rejected', 'slot_taken', 'stored'], [$clash[0]['status'], $clash[0]['code'], $clash[1]['status']]);
        // Another device may not take the id.
        [$otherDevice, $otherToken] = $this->pairedTill($this->locationB, 'Till B');
        $this->assertNotNull($otherDevice);
        $stolen = $this->sync(['appointments' => [[...$this->record('12:00', ['user_id' => $this->owner->id, 'actor_proof' => $this->actorProof($this->owner->id, $otherDevice), 'id' => $record['id']])]]], $otherToken);
        $stolen->assertStatus(422)->assertJsonPath('code', 'upload_rejected')->assertJsonPath('results.appointments.0.code', 'id_conflict');
    }

    public function test_the_person_must_be_proven_and_allowed_and_the_ids_must_be_the_tenants(): void
    {
        $this->setUpSalon();
        // No sign-in proof, a proof for someone else, a person without the permission.
        $results = $this->sync(['appointments' => [
            $this->record('09:00', ['actor_proof' => null]),
            $this->record('09:30', ['actor_proof' => $this->actorProof($this->bobUser->id)]),
            $this->record('10:00', ['user_id' => $this->id(), 'actor_proof' => null]),
            $this->record('10:30', ['service_ids' => null, 'lines' => [['service_id' => $this->id(), 'staff_id' => $this->ann->id]]]),
            $this->record('11:00', ['salon_client_id' => $this->id()]),
            $this->record('11:30', ['lines' => [['service_id' => $this->haircut->id, 'staff_id' => $this->id()]]]),
        ]])->assertStatus(422)->json('results.appointments');
        $this->assertSame(['actor_unverified', 'actor_unverified', 'actor_unknown', 'service_unknown', 'client_unknown', 'staff_unqualified'], array_column($results, 'code'));
        $this->assertSame(0, $this->inTenant(fn () => Appointment::query()->count()));

        // The queue is the desk's: a stylist is refused.
        $this->sync(['queue' => [['id' => $this->id(), 'user_id' => $this->annUser->id, 'actor_proof' => $this->actorProof($this->annUser->id), 'action' => 'join', 'guest_name' => 'X', 'service_ids' => [$this->haircut->id]]]])
            ->assertStatus(422)->assertJsonPath('results.queue.0.code', 'not_permitted');
        // A record of the till's own place only: the desk (branch A) is not allowed at a till of branch B.
        [$deviceB, $tokenB] = $this->pairedTill($this->locationB, 'Till B');
        $this->sync(['appointments' => [$this->record('10:00', ['actor_proof' => $this->actorProof($this->desk->id, $deviceB)])]], $tokenB)->assertStatus(422)->assertJsonPath('results.appointments.0.code', 'not_permitted');
        // A body that is not a batch, or too big, is refused before anything is read.
        $this->sync([])->assertStatus(422);
        $this->sync(['appointments' => array_fill(0, 101, ['id' => $this->id()])])->assertStatus(422);
        // A back-office token is not a till.
        $this->postJson('/api/v1/salon/device/sync', ['appointments' => [$this->record()]], $this->headersFor($this->owner))->assertForbidden();
    }

    public function test_the_steps_of_the_day_come_in_any_order_and_only_from_the_stylist_or_a_manager(): void
    {
        $this->setUpSalon();
        $made = $this->book('10:00');
        $id = $made['id'];

        // Bob cannot work Ann's client.
        $this->sync(['events' => [$this->event($id, 'check_in', $this->bobUser)]])->assertStatus(422)->assertJsonPath('results.events.0.code', 'not_permitted');
        // Out of order, and twice: the last step stands.
        $results = $this->sync(['events' => [$this->event($id, 'finish'), $this->event($id, 'check_in'), $this->event($id, 'start'), $this->event($id, 'finish')]])->assertOk()->json('results.events');
        $this->assertSame(['completed', 'completed', 'completed', 'completed'], array_column($results, 'appointment_status'));
        $this->assertSame('completed', $this->appointment($id)->status);

        // No-show before the time is refused with the reason; a cancellation is the desk's, with a fee inside the window.
        $second = $this->book('11:00');
        $this->sync(['events' => [$this->event($second['id'], 'no_show', $this->desk)]])->assertStatus(422)->assertJsonPath('results.events.0.code', 'too_early');
        $this->sync(['events' => [$this->event($second['id'], 'check_in', $this->annUser), $this->event($second['id'], 'cancel', $this->annUser)]])->assertOk()
            ->assertJsonPath('results.events.0.status', 'stored')->assertJsonPath('results.events.1.code', 'not_permitted');
        $third = $this->book('12:00');
        $cancelled = $this->sync(['events' => [$this->event($third['id'], 'cancel', $this->desk, ['reason' => 'Ill'])]])->assertOk()->json('results.events.0');
        $this->assertSame(['cancelled', '10000'], [$cancelled['appointment_status'], $cancelled['fee_minor']]);
        // Another branch's appointment is not this till's.
        [$deviceB, $tokenB] = $this->pairedTill($this->locationB, 'Till B');
        $this->sync(['events' => [$this->event($id, 'check_in', $this->owner, ['actor_proof' => $this->actorProof($this->owner->id, $deviceB)])]], $tokenB)->assertStatus(422)->assertJsonPath('results.events.0.code', 'appointment_unknown');
    }

    public function test_the_queue_is_joined_seated_and_left_from_the_till_with_the_tills_own_ids(): void
    {
        $this->setUpSalon();
        $this->clockAt('09:30');
        $join = fn (string $name) => ['id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'action' => 'join', 'guest_name' => $name, 'service_ids' => [$this->haircut->id]];
        $one = $join('Walk-in One');
        $two = $join('Walk-in Two');
        $this->sync(['queue' => [$one, $two]])->assertOk();
        $this->sync(['queue' => [$one]])->assertOk()->assertJsonPath('results.queue.0.entry_status', 'waiting');
        $this->assertSame(2, $this->inTenant(fn () => QueueEntry::query()->count()));

        $appointmentId = $this->id();
        $seat = ['id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'action' => 'seat', 'entry_id' => $one['id'], 'staff_id' => $this->ann->id, 'appointment_id' => $appointmentId];
        $seated = $this->sync(['queue' => [$seat]])->assertOk()->json('results.queue.0');
        $this->assertSame(['seated', $appointmentId], [$seated['entry_status'], $seated['appointment_id']]);
        $this->assertSame(['checked_in', 'walk_in', $this->till->id], [$this->appointment($appointmentId)->status, $this->appointment($appointmentId)->source, $this->appointment($appointmentId)->device_id]);
        // The same seating again answers the same; Ann is busy for the next one.
        $this->sync(['queue' => [$seat]])->assertOk()->assertJsonPath('results.queue.0.appointment_id', $appointmentId);
        $busy = ['id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'action' => 'seat', 'entry_id' => $two['id'], 'staff_id' => $this->ann->id];
        $this->sync(['queue' => [$busy]])->assertStatus(422)->assertJsonPath('results.queue.0.code', 'slot_taken');
        $leave = ['id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'action' => 'leave', 'entry_id' => $two['id']];
        $this->sync(['queue' => [$leave]])->assertOk()->assertJsonPath('results.queue.0.entry_status', 'left');
        // An entry that is not this branch's does not exist for the till.
        $this->sync(['queue' => [['id' => $this->id(), 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'action' => 'leave', 'entry_id' => $this->id()]]])->assertStatus(422)->assertJsonPath('results.queue.0.code', 'queue_entry_unknown');
    }

    public function test_the_till_pulls_the_week_ahead_with_the_least_personal_data(): void
    {
        $this->setUpSalon();
        $this->inTenant(fn () => Client::create(['company_id' => $this->acme->id, 'party_id' => $this->client->id, 'allergies' => 'Peanut oil', 'formulas' => 'Level 6 + 20vol', 'notes' => 'Prefers quiet']));
        $made = $this->book('10:00');
        $this->book('11:00', $this->bob, ['salon_client_id' => null, 'guest_name' => 'Walk Guest', 'guest_phone' => '0700999888']);
        $this->api('POST', 'salon/queue', ['branch_id' => $this->branchA->id, 'guest_name' => 'Waiting Wanjiru', 'guest_phone' => '0700111222', 'service_ids' => [$this->haircut->id]], $this->desk)->assertCreated();
        $package = $this->inTenant(fn () => Package::create([
            'company_id' => $this->acme->id, 'type_id' => $this->fiveCuts->id, 'customer_id' => $this->client->id, 'name' => 'Five haircuts', 'sale_id' => $this->id(), 'sale_line_id' => $this->id(),
            'sessions_total' => 5, 'sessions_used' => 1, 'price_minor' => 450000, 'remaining_minor' => 360000, 'currency' => 'KES', 'service_ids' => [$this->haircut->id],
        ]));
        $scope = fn () => DeviceScope::of($this->till);

        $settings = $this->inTenant(fn () => app(SettingsSource::class)->rows($scope()))[0];
        $this->assertSame([$this->acme->id, $this->branchA->id, $this->tipItem->id, $this->feeItem->id, 15, true], [$settings['id'], $settings['branch_id'], $settings['tip_item_id'], $settings['fee_item_id'], $settings['slot_minutes'], $settings['online_enabled']]);

        $staff = collect($this->inTenant(fn () => app(StaffSource::class)->rows($scope())))->keyBy('id');
        $this->assertEqualsCanonicalizing(['Ann', 'Bob', 'Chair 1', 'Chair 2'], $staff->pluck('name')->all());
        $this->assertSame(['staff', 8], [$staff[$this->ann->id]['kind'], count($staff[$this->ann->id]['work'])]);
        $this->assertSame($this->annUser->id, $staff[$this->ann->id]['user_id']);
        $this->assertSame([$this->at('13:00'), $this->at('14:00')], $staff[$this->ann->id]['off'][0]);

        $services = collect($this->inTenant(fn () => app(ServicesSource::class)->rows($scope())))->keyBy('name');
        $this->assertSame(['100000', 30, [$this->beard->id]], [$services['Haircut']['price_minor'], $services['Haircut']['duration_minutes'], $services['Haircut']['addon_ids']]);

        $schedule = $this->inTenant(fn () => app(ScheduleSource::class)->rows($scope()));
        $this->assertCount(2, $schedule);
        $first = collect($schedule)->firstWhere('id', $made['id']);
        $this->assertSame(['Amina Client', true, '0', 'booked'], [$first['client_name'], $first['has_alerts'], $first['deposit_balance_minor'], $first['status']]);
        // No contact details, no notes, nothing sensitive reaches the device.
        $json = json_encode([$schedule, $this->inTenant(fn () => app(QueueSource::class)->rows($scope()))]);

        foreach (['0700999888', '0700111222', 'Peanut', 'Level 6', 'quiet', '254700111222'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        $queue = $this->inTenant(fn () => app(QueueSource::class)->rows($scope()));
        $this->assertSame(['Waiting Wanjiru', 1], [$queue[0]['guest_name'], $queue[0]['position']]);
        $packages = $this->inTenant(fn () => app(PackagesSource::class)->rows($scope()));
        $this->assertSame([$package->id, 4, '90000'], [$packages[0]['id'], $packages[0]['sessions_left'], $packages[0]['session_value_minor']]);

        // The week is bounded: an old and a far-off appointment are not pulled.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00', ['starts_at' => $this->at('10:00', 20)]), $this->desk)->assertCreated();
        $this->assertCount(2, $this->inTenant(fn () => app(ScheduleSource::class)->rows($scope())));
        // Another branch's till gets its own (empty) schedule, and its tenant's data stays out.
        [$deviceB] = $this->pairedTill($this->locationB, 'Till B');
        $this->assertSame([], $this->inTenant(fn () => app(ScheduleSource::class)->rows(DeviceScope::of($deviceB))));
    }

    public function test_the_salon_entities_are_offered_to_a_till_only_while_the_module_is_on(): void
    {
        $this->setUpSalon();
        $keys = fn () => array_column($this->getJson('/api/v1/sync/bootstrap', $this->tillHeaders())->assertOk()->json('entities'), 'key');
        foreach (['salon_settings', 'salon_staff', 'salon_services', 'salon_schedule', 'salon_queue', 'salon_packages'] as $key) {
            $this->assertContains($key, $keys());
        }

        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('salon'));
        $this->assertSame([], array_values(array_filter($keys(), fn ($key) => str_starts_with($key, 'salon_'))));
    }
}
