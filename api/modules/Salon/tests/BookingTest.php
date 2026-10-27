<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\Service;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-06 to SAL-09, SAL-13: open times, booking, clashes, hours, rebooking and the day flow.
class BookingTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    public function test_a_booking_is_priced_numbered_and_blocks_the_staff_member_for_its_time(): void
    {
        $this->setUpSalon();

        $made = $this->book('10:00');
        $this->assertMatchesRegularExpression('/^SAL-A-2027-\d{5}$/', $made['number']);
        $this->assertSame(['booked', '100000', 'KES'], [$made['status'], $made['total_minor'], $made['currency']]);
        $this->assertSame([$this->at('10:00'), $this->at('10:30')], [$made['lines'][0]['starts_at'], $made['lines'][0]['ends_at']]);
        $this->assertSame('Ann', $made['lines'][0]['staff_name']);

        // The same member at the same time, or overlapping, is refused; the next minute after is not.
        $clash = ['lines' => [['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]]];
        $this->api('POST', 'salon/appointments', $this->bookingBody($clash['lines'], '10:00'), $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->api('POST', 'salon/appointments', $this->bookingBody($clash['lines'], '10:15'), $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->api('POST', 'salon/appointments', $this->bookingBody($clash['lines'], '10:30'), $this->desk)->assertCreated();
        // Another member is free.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id]], '10:00'), $this->desk)->assertCreated();
        $this->assertSame(3, $this->inTenant(fn () => Appointment::query()->count()));
    }

    public function test_hours_breaks_time_off_and_qualifications_are_applied_unless_overridden(): void
    {
        $this->setUpSalon();
        $one = fn (string $time, array $line = []) => $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id, ...$line]], $time);

        $this->api('POST', 'salon/appointments', $one('07:30'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $this->api('POST', 'salon/appointments', $one('13:00'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'staff_off');
        $this->api('POST', 'salon/appointments', $one('16:45'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        // Colour is Ann's alone.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->colour->id, 'staff_id' => $this->bob->id]], '10:00'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'staff_unqualified');

        // Only a holder of salon.appointment.override books outside hours, with a reason, and it is audited.
        $this->api('POST', 'salon/appointments', [...$one('07:30'), 'override_reason' => 'Bridal party'], $this->desk)->assertForbidden()->assertJsonPath('code', 'override_not_permitted');
        $override = $this->api('POST', 'salon/appointments', [...$one('07:30'), 'override_reason' => 'Bridal party'], $this->manager)->assertCreated()->json('data');
        $this->assertSame(1, AuditEntry::where('action', 'salon.appointment.override')->where('auditable_id', $override['id'])->count());
        // The clash check is never overridable.
        $this->api('POST', 'salon/appointments', [...$one('07:30'), 'override_reason' => 'Again'], $this->manager)->assertStatus(409)->assertJsonPath('code', 'slot_taken');

        // Time off blocks the day.
        $this->api('POST', "salon/staff/{$this->bob->id}/time-off", ['starts_at' => $this->at('09:00', 1), 'ends_at' => $this->at('17:00', 1), 'reason' => 'leave'], $this->manager)->assertCreated();
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id]], '11:00', ['starts_at' => $this->at('11:00', 1)]), $this->desk)
            ->assertStatus(422)->assertJsonPath('code', 'staff_off');
    }

    public function test_services_resources_addons_and_prices_are_checked(): void
    {
        $this->setUpSalon();

        // A colour takes a chair, Ann's, with 15 minutes to clean up; the chair is picked when none is named.
        $first = $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->colour->id, 'staff_id' => $this->ann->id]], '09:00'), $this->desk)->assertCreated()->json('data.lines.0');
        $this->assertSame($this->chair1->id, $first['resource_id']);
        $this->assertSame([$this->at('09:00'), $this->at('10:30')], [$first['starts_at'], $first['ends_at']]);
        // Ann is busy until the end of the clean-up (10:45).
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:30'), $this->desk)->assertStatus(409);
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:45'), $this->desk)->assertCreated();

        // A named chair that is taken refuses a haircut of Bob's at the same time.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id, 'resource_id' => $this->chair1->id]], '09:30'), $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id, 'resource_id' => $this->chair2->id]], '09:30'), $this->desk)->assertCreated();

        // An add-on only follows a service that offers it, and runs after it.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->beard->id, 'staff_id' => $this->bob->id]], '15:00'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'addon_not_offered');
        $both = $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->bob->id], ['service_id' => $this->beard->id, 'staff_id' => $this->bob->id]], '15:00'), $this->desk)
            ->assertCreated()->json('data');
        $this->assertSame('150000', $both['total_minor']);
        $this->assertSame([$this->at('15:00'), $this->at('15:30'), $this->at('15:45')], [$both['lines'][0]['starts_at'], $both['lines'][1]['starts_at'], $both['lines'][1]['ends_at']]);

        // A service whose item has no list price cannot be booked (nobody types prices).
        $bare = $this->inTenant(function () {
            $item = Item::create(['code' => 'BARE', 'name' => 'Bare', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);

            return Service::create(['company_id' => $this->acme->id, 'item_id' => $item->id, 'name' => 'Bare', 'duration_minutes' => 30]);
        });
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $bare->id, 'staff_id' => $this->bob->id]], '16:00'), $this->desk)->assertStatus(422)->assertJsonPath('code', 'price_missing');
    }

    public function test_ids_from_another_tenant_or_branch_are_refused(): void
    {
        $this->setUpSalon();
        $other = $this->otherSalonTenant();
        $foreign = $this->asTenant($other['user']->tenant_id, fn () => Service::query()->count());
        $this->assertSame(0, $foreign);

        // A stranger's client or staff id does not exist for this tenant.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00', ['client_id' => $this->id()]), $this->desk)->assertStatus(422);
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->id()]], '10:00'), $this->desk)->assertStatus(422);
        // A supplier is not a client.
        $supplier = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id]));
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00', ['client_id' => $supplier->id]), $this->desk)->assertStatus(422)->assertJsonPath('code', 'client_unknown');
        // The desk works at branch A only.
        $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00', ['branch_id' => $this->branchB->id]), $this->desk)->assertForbidden();
        // The other tenant's user cannot read this appointment, nor the module of a tenant without it be reached.
        $made = $this->book('10:00');
        $this->api('GET', "salon/appointments/{$made['id']}", [], null)->assertOk();
        $this->getJson("/api/v1/salon/appointments/{$made['id']}", $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_open_times_follow_the_grid_the_hours_and_what_is_booked(): void
    {
        $this->setUpSalon();
        $this->book('10:00');

        $slots = $this->api('GET', 'salon/availability?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today, 'service_ids' => [$this->haircut->id], 'staff_id' => $this->ann->id]), [], $this->desk)
            ->assertOk()->json('data.slots');
        $starts = array_column($slots, 'starts_at');
        $this->assertContains($this->at('09:00'), $starts);
        $this->assertNotContains($this->at('10:00'), $starts);
        $this->assertNotContains($this->at('09:45'), $starts, 'a 30-minute service from 09:45 would run into the booking at 10:00');
        $this->assertContains($this->at('10:30'), $starts);
        $this->assertNotContains($this->at('13:00'), $starts);
        $this->assertNotContains($this->at('12:45'), $starts);
        $this->assertContains($this->at('14:00'), $starts);
        $this->assertNotContains($this->at('16:45'), $starts);
        $this->assertContains($this->at('16:30'), $starts);

        // Anyone: Bob is free at 10:00, so the time is on offer with Bob.
        $any = $this->api('GET', 'salon/availability?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today, 'service_ids' => [$this->haircut->id]]), [], $this->desk)->assertOk()->json('data.slots');
        $at10 = collect($any)->firstWhere('starts_at', $this->at('10:00'));
        $this->assertSame($this->bob->id, $at10['lines'][0]['staff_id']);
    }

    public function test_an_appointment_moves_and_a_failed_move_changes_nothing(): void
    {
        $this->setUpSalon();
        $a = $this->book('10:00');
        $b = $this->book('11:00');

        // Into the other booking: refused, and the first stays where it was.
        $this->api('POST', "salon/appointments/{$a['id']}/move", ['starts_at' => $this->at('11:00')], $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertSame($this->at('10:00'), $this->appointment($a['id'])->starts_at->toIso8601String());

        // To a free time, with another member: the old time is free again at once.
        $moved = $this->api('POST', "salon/appointments/{$a['id']}/move", ['starts_at' => $this->at('15:00'), 'lines' => [['id' => $a['lines'][0]['id'], 'staff_id' => $this->bob->id]]], $this->desk)->assertOk()->json('data');
        $this->assertSame([$this->at('15:00'), $this->bob->id], [$moved['starts_at'], $moved['lines'][0]['staff_id']]);
        $this->book('10:00');
        // A line of another appointment is not movable here.
        $this->api('POST', "salon/appointments/{$a['id']}/move", ['starts_at' => $this->at('16:00'), 'lines' => [['id' => $b['lines'][0]['id']]]], $this->desk)->assertStatus(422)->assertJsonPath('code', 'line_unknown');
        $this->assertSame(1, AuditEntry::where('action', 'salon.appointment.move')->count());
    }

    public function test_cancellation_and_no_show_make_fees_that_are_waived_by_a_manager_only(): void
    {
        $this->setUpSalon();

        // Inside the 24-hour window a cancellation costs 10 % of the booking; its time is free again.
        $soon = $this->book('10:00');
        $cancelled = $this->api('POST', "salon/appointments/{$soon['id']}/cancel", ['reason' => 'Changed plans'], $this->desk)->assertOk()->json('data');
        $this->assertSame(['cancelled', '10000', 'due', 'late_cancel'], [$cancelled['status'], $cancelled['fee_minor'], $cancelled['fee_status'], $cancelled['fee_reason']]);
        $this->book('10:00');
        // Cancelling again changes nothing; a moved-on appointment cannot be cancelled twice into a second fee.
        $this->api('POST', "salon/appointments/{$soon['id']}/cancel", ['reason' => 'Again'], $this->desk)->assertOk()->assertJsonPath('data.fee_minor', '10000');

        // Outside the window it is free.
        $later = $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]], '10:00', ['starts_at' => $this->at('10:00', 3)]), $this->desk)->assertCreated()->json('data');
        $this->api('POST', "salon/appointments/{$later['id']}/cancel", ['reason' => 'Holiday'], $this->desk)->assertOk()->assertJsonPath('data.fee_status', 'none')->assertJsonPath('data.fee_minor', '0');

        // A no-show waits for the start time and costs 20 %.
        $absent = $this->book('11:00', $this->bob);
        $this->api('POST', "salon/appointments/{$absent['id']}/no-show", ['reason' => 'Did not come'], $this->desk)->assertStatus(409)->assertJsonPath('code', 'too_early');
        $this->clockAt('11:20');
        $this->api('POST', "salon/appointments/{$absent['id']}/no-show", ['reason' => 'Did not come'], $this->desk)->assertOk()->assertJsonPath('data.fee_minor', '20000')->assertJsonPath('data.fee_reason', 'no_show');

        // Only a holder of salon.fee.waive waives, with a reason; it is audited.
        $this->api('POST', "salon/appointments/{$absent['id']}/waive-fee", ['reason' => 'Regular client'], $this->desk)->assertForbidden();
        $this->api('POST', "salon/appointments/{$absent['id']}/waive-fee", [], $this->manager)->assertUnprocessable();
        $this->api('POST', "salon/appointments/{$absent['id']}/waive-fee", ['reason' => 'Regular client'], $this->manager)->assertOk()->assertJsonPath('data.fee_status', 'waived');
        $this->assertSame(1, AuditEntry::where('action', 'salon.fee.waive')->count());
        $this->api('POST', "salon/appointments/{$absent['id']}/waive-fee", ['reason' => 'Twice'], $this->manager)->assertOk();
        $this->assertSame(0, $this->inTenant(fn () => Line::query()->where('appointment_id', $absent['id'])->where('holds', true)->count()));
    }

    public function test_the_day_flow_is_forward_only_and_a_stylist_serves_only_their_own_clients(): void
    {
        $this->setUpSalon();
        $made = $this->book('10:00');
        $id = $made['id'];

        // Bob cannot serve Ann's client; Ann can.
        $this->api('POST', "salon/appointments/{$id}/check-in", [], $this->bobUser)->assertForbidden();
        $this->api('POST', "salon/appointments/{$id}/check-in", [], $this->annUser)->assertOk()->assertJsonPath('data.status', 'checked_in');
        $this->api('POST', "salon/appointments/{$id}/check-in", [], $this->annUser)->assertOk()->assertJsonPath('data.status', 'checked_in');
        $this->api('POST', "salon/appointments/{$id}/start", [], $this->annUser)->assertOk()->assertJsonPath('data.status', 'in_service');
        $this->api('POST', "salon/appointments/{$id}/finish", [], $this->annUser)->assertOk()->assertJsonPath('data.status', 'completed');
        // A late check-in or start does not take it back.
        $this->api('POST', "salon/appointments/{$id}/check-in", [], $this->desk)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->api('POST', "salon/appointments/{$id}/start", [], $this->desk)->assertOk()->assertJsonPath('data.status', 'completed');
        // A completed visit can no longer be cancelled.
        $this->api('POST', "salon/appointments/{$id}/cancel", ['reason' => 'No'], $this->desk)->assertStatus(409)->assertJsonPath('code', 'appointment_not_open');

        // A stylist's list holds only their own appointments; the desk sees all.
        $this->book('11:00', $this->bob);
        $own = $this->api('GET', 'salon/appointments?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today]), [], $this->bobUser)->assertOk();
        $this->assertCount(1, $own->json('data'));
        $this->api('GET', "salon/appointments/{$id}", [], $this->bobUser)->assertForbidden();
        $this->assertCount(2, $this->api('GET', 'salon/appointments?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today]), [], $this->desk)->json('data'));
    }

    public function test_walk_ins_wait_in_the_queue_and_are_seated_with_a_free_member(): void
    {
        $this->setUpSalon();
        $entry = $this->api('POST', 'salon/queue', ['branch_id' => $this->branchA->id, 'guest_name' => 'Walk-in Wendy', 'guest_phone' => '0711000111', 'service_ids' => [$this->haircut->id]], $this->desk)->assertCreated()->json('data');
        $second = $this->api('POST', 'salon/queue', ['branch_id' => $this->branchA->id, 'guest_name' => 'Second', 'service_ids' => [$this->haircut->id], 'staff_id' => $this->ann->id], $this->desk)->assertCreated()->json('data');
        $list = $this->api('GET', 'salon/queue?branch_id='.$this->branchA->id, [], $this->desk)->assertOk()->json('data');
        $this->assertSame([1, 2], array_column($list, 'position'));
        $this->assertSame(['Walk-in Wendy', 'Second'], array_column($list, 'guest_name'));

        // Seating books a checked-in appointment starting now (the clock is 08:00, before Ann starts at 09:00: an override is needed).
        $this->api('POST', "salon/queue/{$entry['id']}/seat", ['staff_id' => $this->ann->id], $this->desk)->assertStatus(422)->assertJsonPath('code', 'outside_hours');
        $this->clockAt('09:30');
        $seated = $this->api('POST', "salon/queue/{$entry['id']}/seat", ['staff_id' => $this->ann->id], $this->desk)->assertOk()->json('data');
        $this->assertSame(['seated', 'checked_in', 'walk_in'], [$seated['entry']['status'], $seated['appointment']['status'], $seated['appointment']['source']]);
        // Seating the same entry again answers the same appointment; Ann is busy for the next.
        $this->api('POST', "salon/queue/{$entry['id']}/seat", ['staff_id' => $this->ann->id], $this->desk)->assertOk()->assertJsonPath('data.appointment.id', $seated['appointment']['id']);
        $this->api('POST', "salon/queue/{$second['id']}/seat", ['staff_id' => $this->ann->id], $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertSame('waiting', $this->api('GET', 'salon/queue?branch_id='.$this->branchA->id, [], $this->desk)->json('data.0.status'));
        $this->api('POST', "salon/queue/{$second['id']}/leave", [], $this->desk)->assertOk()->assertJsonPath('data.status', 'left');
        $this->assertSame([], $this->api('GET', 'salon/queue?branch_id='.$this->branchA->id, [], $this->desk)->json('data'));
        // A stylist cannot run the queue's seating (no queue.manage).
        $this->api('POST', 'salon/queue', ['branch_id' => $this->branchA->id, 'guest_name' => 'X', 'service_ids' => [$this->haircut->id]], $this->annUser)->assertForbidden();
    }
}
