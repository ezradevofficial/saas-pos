<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\Scope;
use Illuminate\Support\Facades\Event;
use Modules\Hotel\Events\StayCheckedIn;
use Modules\Hotel\Events\StayCheckedOut;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Models\Stay;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-17 to HOT-19, HOT-37: assigning rooms, check-in, check-out, fees, moving a guest, walk-ins.
class FrontDeskTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    private function room(string $number): Room
    {
        return $this->inTenant(fn () => Room::query()->find($this->rooms[$number]->id));
    }

    public function test_check_in_occupies_the_room_and_check_out_leaves_it_dirty_with_a_clean_task(): void
    {
        Event::fake([StayCheckedIn::class, StayCheckedOut::class]);
        $stay = $this->checkedIn(1, '101');
        $this->assertSame(['checked_in', '101'], [$stay['status'], $stay['room']['number']]);
        $this->assertSame(Room::OCCUPIED, $this->room('101')->status);
        Event::assertDispatched(StayCheckedIn::class);

        $out = $this->postJson('/api/v1/hotel/stays/'.$stay['id'].'/check-out', [], $this->headersFor())->assertOk()->json('data');
        $this->assertSame('checked_out', $out['stay']['status']);
        $this->assertSame('settled', $out['folio']['status']);
        $this->assertSame(Room::VACANT_DIRTY, $this->room('101')->status);
        $this->assertSame(1, $this->inTenant(fn () => HousekeepingTask::query()->where('room_id', $this->rooms['101']->id)->where('kind', 'checkout_clean')->count()));
        Event::assertDispatched(StayCheckedOut::class);

        // A dirty room cannot be given to the next guest until housekeeping cleans it.
        $next = $this->book(1)['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$next}/check-in", ['room_id' => $this->rooms['101']->id], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'room_not_ready');
    }

    public function test_a_room_cannot_go_to_two_guests_or_be_out_of_order_and_a_room_of_another_branch_is_unknown(): void
    {
        $first = $this->checkedIn(2, '101');
        $second = $this->book(2)['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$second}/assign-room", ['room_id' => $this->rooms['101']->id], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'room_taken');
        $this->postJson('/api/v1/hotel/rooms/'.$this->rooms['102']->id.'/status', ['status' => 'out_of_order', 'reason' => 'Plumbing'], $this->headersFor())->assertOk();
        $this->postJson("/api/v1/hotel/stays/{$second}/assign-room", ['room_id' => $this->rooms['102']->id], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'room_out_of_order');
        $this->postJson("/api/v1/hotel/stays/{$second}/assign-room", ['room_id' => $this->rooms['103']->id], $this->headersFor())->assertOk()->assertJsonPath('data.room.number', '103');
        $this->assertSame('101', $first['room']['number']);

        $other = $this->inTenant(fn () => Room::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchB->id, 'room_type_id' => $this->deluxe->id, 'number' => '901']));
        $this->postJson("/api/v1/hotel/stays/{$second}/assign-room", ['room_id' => $other->id], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'room_unknown');
    }

    public function test_the_guest_id_is_required_when_the_company_says_so_and_check_in_before_arrival_needs_permission(): void
    {
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['require_guest_id' => true]));
        $this->inTenant(fn () => $this->guest->forceFill(['id_number' => null])->save());
        $stay = $this->book(2)['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$stay}/check-in", ['room_id' => $this->rooms['101']->id], $this->headersFor())->assertStatus(422)->assertJsonPath('code', 'guest_id_required');
        $this->patchJson('/api/v1/hotel/guests/'.$this->guest->id, ['id_type' => 'passport', 'id_number' => 'P7777'], $this->headersFor())->assertOk()->assertJsonPath('data.has_identity_document', true);
        $this->postJson("/api/v1/hotel/stays/{$stay}/check-in", ['room_id' => $this->rooms['101']->id], $this->headersFor())->assertOk();

        $future = $this->book(2, ['arrival_on' => $this->day(2), 'departure_on' => $this->day(4)])['stays'][0]['id'];
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $this->postJson("/api/v1/hotel/stays/{$future}/check-in", ['room_id' => $this->rooms['102']->id], $this->headersFor($receptionist))->assertStatus(409)->assertJsonPath('code', 'arrival_in_future');
        // The owner may: the stay then starts today and is re-priced.
        $this->postJson("/api/v1/hotel/stays/{$future}/check-in", ['room_id' => $this->rooms['102']->id], $this->headersFor())->assertOk()->assertJsonPath('data.arrival_on', $this->day(0))->assertJsonPath('data.nights', 4);
    }

    public function test_early_and_late_fees_post_unless_waived_by_someone_allowed(): void
    {
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['early_checkin_fee_minor' => 50000, 'late_checkout_fee_minor' => 150000]));
        $receptionist = $this->userWith('receptionist', Scope::branch($this->branchA->id));
        $a = $this->book(1)['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$a}/check-in", ['room_id' => $this->rooms['101']->id, 'waive_fees' => true], $this->headersFor($receptionist))->assertStatus(422);
        $this->postJson("/api/v1/hotel/stays/{$a}/check-in", ['room_id' => $this->rooms['101']->id], $this->headersFor($receptionist))->assertOk();
        $b = $this->book(1)['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$b}/check-in", ['room_id' => $this->rooms['102']->id, 'waive_fees' => true], $this->headersFor())->assertOk();

        $fees = fn (string $stay) => $this->inTenant(fn () => FolioLine::query()->whereIn('folio_id', Folio::query()->where('stay_id', $stay)->select('id'))->where('kind', 'fee')->pluck('amount_minor')->all());
        $this->assertSame([50000], $fees($a));
        $this->assertSame([], $fees($b));

        // The night is audited; leaving after the check-out time on the departure day (move the clock to 13:00) costs the late fee.
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['check_out_time' => '08:00']));
        $this->postJson("/api/v1/hotel/stays/{$b}/check-out", [], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'folio_balance');
        $this->postJson("/api/v1/hotel/stays/{$b}/check-out", ['payments' => [['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1150000]]], $this->headersFor())->assertOk()->assertJsonPath('data.folio.status', 'settled');
        $this->assertSame([150000], $fees($b));
    }

    public function test_a_walk_in_books_and_checks_in_in_one_step_and_a_guest_can_be_moved(): void
    {
        $r = $this->postJson('/api/v1/hotel/walk-ins', [
            'branch_id' => $this->branchA->id, 'guest_id' => $this->guest->id, 'room_id' => $this->rooms['103']->id, 'rate_plan_id' => $this->bb->id, 'departure_on' => $this->day(2), 'adults' => 2, 'keys_issued' => 2,
        ], $this->headersFor())->assertCreated()->json('data');
        $this->assertSame('walk_in', $r['kind']);
        $this->assertSame(['checked_in', '103', 2], [$r['stays'][0]['status'], $r['stays'][0]['room']['number'], $r['stays'][0]['keys_issued']]);

        $id = $r['stays'][0]['id'];
        $this->postJson("/api/v1/hotel/stays/{$id}/move-room", ['room_id' => $this->rooms['103']->id, 'reason' => 'Same'], $this->headersFor())->assertStatus(422);
        $this->postJson("/api/v1/hotel/stays/{$id}/move-room", ['room_id' => $this->rooms['102']->id, 'reason' => 'Noisy room'], $this->headersFor())->assertOk()->assertJsonPath('data.room.number', '102');
        $this->assertSame([Room::VACANT_DIRTY, Room::OCCUPIED], [$this->room('103')->status, $this->room('102')->status]);
        $this->assertGreaterThan(0, $this->audited('hotel.stay.move_room'));
    }

    public function test_a_group_master_folio_settles_when_the_last_guest_leaves(): void
    {
        $r = $this->postJson('/api/v1/hotel/reservations', $this->bookingBody(1, ['rooms' => 2], ['kind' => 'group', 'group_name' => 'Choir']), $this->headersFor())->assertCreated()->json('data');
        $master = collect($r['folios'])->firstWhere('kind', 'master');
        $this->postJson('/api/v1/hotel/folios/'.$master['id'].'/payments', ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 100000, 'kind' => 'deposit'], $this->headersFor())->assertCreated();

        foreach ([0, 1] as $i) {
            $this->postJson('/api/v1/hotel/stays/'.$r['stays'][$i]['id'].'/check-in', ['room_id' => $this->rooms[['101', '102'][$i]]->id], $this->headersFor())->assertOk();
        }

        // Each night's charge goes to the master folio, not the guests' own.
        $this->postJson('/api/v1/hotel/night-audits', $this->auditBody(), $this->headersFor())->assertCreated();
        $balance = fn (string $id) => $this->getJson("/api/v1/hotel/folios/{$id}", $this->headersFor())->json('data.balance_minor');
        $this->assertSame('1900000', $balance($master['id']));

        $this->postJson('/api/v1/hotel/stays/'.$r['stays'][0]['id'].'/check-out', [], $this->headersFor())->assertOk();
        $this->assertSame('open', $this->getJson('/api/v1/hotel/folios/'.$master['id'], $this->headersFor())->json('data.status'));
        // The last guest leaves with the master unpaid: it stays open until it is paid and settled.
        $this->postJson('/api/v1/hotel/stays/'.$r['stays'][1]['id'].'/check-out', [], $this->headersFor())->assertOk();
        $this->assertSame('open', $this->getJson('/api/v1/hotel/folios/'.$master['id'], $this->headersFor())->json('data.status'));
        $this->postJson('/api/v1/hotel/folios/'.$master['id'].'/payments', ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 1900000], $this->headersFor())->assertCreated();
        $this->postJson('/api/v1/hotel/folios/'.$master['id'].'/settle', [], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'settled');
        $invoice = $this->getJson('/api/v1/hotel/folios/'.$master['id'].'/print', $this->headersFor())->assertOk()->json('data.invoice');
        $this->assertSame('2000000', $invoice['gross_minor']);
        $this->assertCount(2, $invoice['lines']);
    }
}
