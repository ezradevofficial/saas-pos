<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\Scope;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-11 to HOT-13, HOT-15, HOT-37: availability, bookings, group bookings, changes, cancellation and no-show.
class BookingTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    public function test_a_booking_is_priced_numbered_and_gets_a_folio(): void
    {
        $r = $this->book(3, ['adults' => 3]);

        $this->assertStringStartsWith('RS-A-2027-', $r['number']);
        $stay = $r['stays'][0];
        // Wed, Thu weekdays (10,000.00 + 2,000.00 extra adult), Fri weekend (12,000.00 + 2,000.00).
        $this->assertSame(['1200000', '1200000', '1400000'], array_column($stay['nightly'], 'amount_minor'));
        $this->assertSame('3800000', $stay['total_minor']);
        $this->assertSame('reserved', $stay['status']);
        $this->assertCount(1, $r['folios']);
        $this->assertStringStartsWith('FO-A-2027-', $r['folios'][0]['number']);
        $this->assertGreaterThan(0, $this->audited('hotel.reservation.create'));
    }

    public function test_the_last_room_cannot_be_booked_twice_and_the_grid_shows_it(): void
    {
        $this->book(2, ['rooms' => 1]);
        $this->book(2);
        $this->book(2);
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2), $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'not_available');
        // A night after the booked ones is free again.
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(2), 'departure_on' => $this->day(3)]), $this->headersFor())->assertCreated();

        $grid = $this->getJson('/api/v1/hotel/availability?branch_id='.$this->branchA->id.'&from='.$this->day(0).'&to='.$this->day(3), $this->headersFor())->assertOk()->json('data');
        $nights = $grid['types'][0]['nights'];
        $this->assertSame([0, 0, 2], array_column($nights, 'free'));
        $this->assertSame(3, $nights[0]['rooms']);
        $this->assertCount(3, $grid['rooms']);
        $this->assertCount(4, $grid['unassigned']);
    }

    public function test_a_room_out_of_order_is_not_sold_and_a_range_check_is_refused_when_too_long(): void
    {
        foreach (['101', '102'] as $number) {
            $this->postJson('/api/v1/hotel/rooms/'.$this->rooms[$number]->id.'/status', ['status' => 'out_of_order', 'reason' => 'Painting', 'from' => $this->day(1), 'until' => $this->day(1)], $this->headersFor())->assertOk();
        }

        $this->book(1);
        $this->book(1);
        // Night 1: only room 103 is sellable and it is taken by the second booking.
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(1), 'departure_on' => $this->day(2)]), $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(1), 'departure_on' => $this->day(2)]), $this->headersFor())->assertStatus(409);
        $this->getJson('/api/v1/hotel/availability?branch_id='.$this->branchA->id.'&from='.$this->day(0).'&to='.$this->day(70), $this->headersFor())->assertStatus(422);
    }

    public function test_a_group_books_many_rooms_with_a_master_folio_and_validates_occupancy(): void
    {
        $r = $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2, ['rooms' => 3], ['kind' => 'group', 'group_name' => 'Safari club']), $this->headersFor())
            ->assertCreated()->assertJsonCount(3, 'data.stays')->json('data');
        $this->assertSame('group', $r['kind']);
        $kinds = array_column($r['folios'], 'kind');
        sort($kinds);
        $this->assertSame(['guest', 'guest', 'guest', 'master'], $kinds);
        $this->assertTrue($r['stays'][0]['bill_to_master']);
        // Two rooms are not left for another booking of three.
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2, ['rooms' => 2], ['kind' => 'group', 'group_name' => 'Late']), $this->headersFor())->assertStatus(409);
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2, ['rooms' => 2]), $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'stays_invalid');
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2, ['adults' => 5]), $this->headersFor())->assertStatus(422);
    }

    public function test_a_guest_can_be_created_inline_and_an_arrival_in_the_past_is_refused(): void
    {
        $body = $this->bookingBody(1);
        unset($body['guest_id']);
        $body['guest'] = ['name' => 'Walk Up', 'phone' => '+254711222333', 'id_type' => 'passport', 'id_number' => 'P1234567'];
        $this->postJson('/api/v1/hotel/reservations', $body, $this->headersFor())->assertCreated()->assertJsonPath('data.guest.name', 'Walk Up');
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['arrival_on' => $this->day(-1), 'departure_on' => $this->day(0)]), $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'arrival_in_past');
    }

    public function test_dates_can_change_before_arrival_and_extend_in_house_with_availability_rechecked(): void
    {
        $r = $this->book(2);
        $id = $r['stays'][0]['id'];
        $this->patchJson("/api/v1/hotel/stays/{$id}", ['departure_on' => $this->day(4)], $this->headersFor())->assertOk()->assertJsonPath('data.nights', 4)->assertJsonPath('data.total_minor', '4400000');
        // Three other bookings take every room from the fourth night, so the stay cannot run on past it.
        foreach (range(1, 3) as $ignored) {
            $this->book(3, ['arrival_on' => $this->day(4), 'departure_on' => $this->day(7)]);
        }

        $this->patchJson("/api/v1/hotel/stays/{$id}", ['departure_on' => $this->day(6)], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'not_available');
        $this->patchJson("/api/v1/hotel/stays/{$id}", ['arrival_on' => $this->day(-2)], $this->headersFor())->assertStatus(422);
        $this->assertGreaterThan(0, $this->audited('hotel.stay.update'));
    }

    public function test_a_typed_rate_needs_the_override_permission_and_a_reason(): void
    {
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['rate_minor' => 500000, 'rate_reason' => 'Corporate']), $this->headersFor($receptionist))->assertStatus(422);
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['rate_minor' => 500000]), $this->headersFor())->assertStatus(422);
        $r = $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['rate_minor' => 500000, 'rate_reason' => 'Corporate']), $this->headersFor())->assertCreated()->json('data');
        $this->assertSame('500000', $r['stays'][0]['total_minor']);
        $this->assertTrue($r['stays'][0]['rate_overridden']);
        $this->assertGreaterThan(0, $this->audited('hotel.stay.rate_override'));
    }

    public function test_cancelling_inside_the_free_window_costs_nothing_and_late_costs_the_configured_charge(): void
    {
        // Free until one day before arrival, then one night (the defaults). Arrival in 3 days: free.
        $far = $this->book(2, ['arrival_on' => $this->day(3), 'departure_on' => $this->day(5)]);
        $this->postJson('/api/v1/hotel/stays/'.$far['stays'][0]['id'].'/cancel', ['reason' => 'Change of plans'], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'cancelled');
        $folio = $this->inTenant(fn () => Folio::query()->where('stay_id', $far['stays'][0]['id'])->first());
        $this->assertSame('settled', $folio->status);
        $this->assertSame(0, $this->inTenant(fn () => FolioLine::query()->where('folio_id', $folio->id)->count()));

        // Arrival today: inside the window, one night charged to the folio, which settles into an invoice once paid.
        $near = $this->book(2);
        $id = $near['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$id}/cancel", ['reason' => 'Ill'], $this->headersFor())->assertOk();
        $nearFolio = $this->inTenant(fn () => Folio::query()->where('stay_id', $id)->first());
        $this->assertSame('open', $nearFolio->status);
        $fee = $this->inTenant(fn () => FolioLine::query()->where('folio_id', $nearFolio->id)->first());
        $this->assertSame(['fee', 1000000], [$fee->kind, $fee->amount_minor]);
        $this->postJson("/api/v1/hotel/stays/{$id}/cancel", ['reason' => 'again'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'stay_not_reserved');

        // A waived charge needs the permission and a reason; a receptionist cannot waive.
        $third = $this->book(1);
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $this->postJson('/api/v1/hotel/stays/'.$third['stays'][0]['id'].'/cancel', ['reason' => 'VIP', 'waive_charge' => true], $this->headersFor($receptionist))->assertStatus(422);
        $this->postJson('/api/v1/hotel/stays/'.$third['stays'][0]['id'].'/cancel', ['reason' => 'VIP', 'waive_charge' => true], $this->headersFor())->assertOk();
        $this->assertSame(0, $this->inTenant(fn () => FolioLine::query()->whereIn('folio_id', Folio::query()->where('stay_id', $third['stays'][0]['id'])->select('id'))->count()));
    }

    public function test_a_no_show_is_charged_by_the_rules_and_only_when_due(): void
    {
        $later = $this->book(2, ['arrival_on' => $this->day(2), 'departure_on' => $this->day(4)]);
        $this->postJson('/api/v1/hotel/stays/'.$later['stays'][0]['id'].'/no-show', [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'no_show_too_early');

        $due = $this->book(2);
        $this->postJson('/api/v1/hotel/stays/'.$due['stays'][0]['id'].'/no-show', ['reason' => 'Did not arrive'], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->assertSame(Stay::NO_SHOW, $this->inTenant(fn () => Stay::find($due['stays'][0]['id'])->status));
        // The room is free again for the same dates.
        $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(2), $this->headersFor())->assertCreated();
        $this->assertGreaterThan(0, $this->audited('hotel.reservation.no_show'));
    }
}
