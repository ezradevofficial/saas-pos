<?php

namespace Modules\Hotel\Tests;

use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Hotel\Contracts\RoomCharges;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Property;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Pos\PosCharges;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Modules\POS\Models\Sale;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The security review of the Hotel module: a room charge is the folio the till charged, never charged twice, a night audit names its date,
// a void keeps to a limit, the audit trail is complete and a charge uses the master-data price.
class HardeningTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
    }

    private function folioOf(array $stay): string
    {
        return $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
    }

    private function charge(string $room, string $source, string $sourceId, ?string $folio, int $amount = 50000): array
    {
        return $this->inTenant(fn () => app(RoomCharges::class)->post([
            'branch_id' => $this->branchA->id, 'room_number' => $room, 'amount_minor' => $amount, 'currency' => 'KES', 'source' => $source, 'source_id' => $sourceId, 'folio_id' => $folio,
        ]));
    }

    private function posLines(): int
    {
        return $this->inTenant(fn () => FolioLine::query()->where('kind', 'pos_charge')->count());
    }

    // ---- 1: the folio the till charged ------------------------------------------------------------------------------

    public function test_a_room_charge_is_not_posted_to_another_guests_folio_after_a_room_change(): void
    {
        $first = $this->checkedIn(1, '101');
        $oldFolio = $this->folioOf($first);
        $this->postJson("/api/v1/hotel/stays/{$first['id']}/check-out", [], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/hotel/rooms/'.$this->rooms['101']->id.'/status', ['status' => 'vacant_clean'], $this->headersFor())->assertOk();
        $second = $this->checkedIn(2, '101');
        $newFolio = $this->folioOf($second);

        // The till synced while the first guest was in the room: the charge names that guest's folio.
        $result = $this->charge('101', 'pos.sale', 'pay-1', $oldFolio);
        $this->assertSame([false, 'folio_changed'], [$result['posted'], $result['reason']]);
        $this->assertSame(0, $this->posLines());

        // The same room with the folio the till knew now is theirs.
        $this->assertTrue($this->charge('101', 'pos.sale', 'pay-2', $newFolio)['posted']);
        $this->assertSame(1, $this->posLines());
    }

    public function test_a_sale_and_a_refund_for_a_changed_folio_become_exceptions_and_the_tender_is_flagged(): void
    {
        $first = $this->checkedIn(1, '101');
        $oldFolio = $this->folioOf($first);
        $this->postJson("/api/v1/hotel/stays/{$first['id']}/check-out", [], $this->headersFor())->assertOk();
        $this->postJson('/api/v1/hotel/rooms/'.$this->rooms['101']->id.'/status', ['status' => 'vacant_clean'], $this->headersFor())->assertOk();
        $this->checkedIn(2, '101');
        $this->ranges()->assertOk();
        $sale = $this->saleBody($this->openShift(), 1);
        $sale['payments'] = [['id' => $this->id(), 'payment_method_id' => $this->roomCharge->id, 'currency' => 'KES', 'amount_minor' => $sale['totals']['total_minor'], 'amount_in_sale_minor' => $sale['totals']['total_minor'], 'rate' => null, 'status' => 'confirmed', 'tender' => ['room' => '101', 'folio_id' => $oldFolio]]];

        $this->upload([$sale])->assertOk();
        $flags = $this->inTenant(fn () => Sale::query()->whereKey($sale['id'])->first()->flags);
        $this->assertSame('room_charge_folio_changed', $flags[0]['detail']['code']);
        $this->assertSame(['folio_changed'], $this->inTenant(fn () => ChargeException::query()->pluck('reason')->all()));
        $this->assertSame(0, $this->posLines());

        $refund = ['id' => $this->id(), 'branch_id' => $this->branchA->id, 'company_id' => $this->acme->id, 'receipt_number' => 'RF-1',
            'payments' => [['id' => $this->id(), 'method_type' => 'room_charge', 'currency' => 'KES', 'amount_minor' => '30000', 'tender' => ['room' => '101', 'folio_id' => $oldFolio]]]];
        $this->inTenant(fn () => app(PosCharges::class)->refunded($refund));
        $this->assertSame(0, $this->posLines());
        $this->assertSame(2, $this->inTenant(fn () => ChargeException::query()->where('reason', 'folio_changed')->count()));
    }

    // ---- 2: once only ---------------------------------------------------------------------------------------------

    public function test_a_charge_moved_to_another_folio_is_never_posted_again_and_the_database_refuses_a_second_line(): void
    {
        $one = $this->folioOf($this->checkedIn(2, '101'));
        $two = $this->folioOf($this->checkedIn(2, '102'));
        $first = $this->charge('101', 'pos.sale', 'pay-9', null);
        $this->assertTrue($first['posted']);
        $this->postJson("/api/v1/hotel/folios/{$one}/transfers", ['to_folio_id' => $two, 'line_ids' => [$first['line_id']], 'reason' => 'Paid by the other room'], $this->headersFor())->assertCreated();

        // The sale synced again, to the room it named: answered with the line it already has, on any folio.
        $again = $this->charge('101', 'pos.sale', 'pay-9', null);
        $this->assertSame([false, true, $first['line_id']], [$again['posted'], $again['duplicate'], $again['line_id']]);
        $this->assertSame(1, $this->posLines() - $this->inTenant(fn () => FolioLine::query()->where('kind', 'pos_charge')->where('source', 'transfer')->count()));

        $this->expectException(QueryException::class);
        $this->inTenant(fn () => DB::table('hotel_folio_lines')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->owner->tenant_id, 'folio_id' => $two, 'line_no' => 99, 'kind' => 'pos_charge', 'description' => 'dup', 'qty' => 1,
            'amount_minor' => 5, 'net_minor' => 5, 'tax_minor' => 0, 'taxes' => '[]', 'currency' => 'KES', 'business_date' => $this->today, 'source' => 'pos.sale', 'source_id' => 'pay-9', 'flags' => '[]',
        ]));
    }

    public function test_moving_an_exception_whose_charge_is_already_on_a_folio_is_refused(): void
    {
        $leaver = $this->checkedIn(1, '101');
        $this->postJson("/api/v1/hotel/stays/{$leaver['id']}/check-out", [], $this->headersFor())->assertOk();
        $other = $this->folioOf($this->checkedIn(2, '102'));
        $exception = $this->inTenant(fn () => ChargeException::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'source' => 'pos.sale', 'source_id' => 'pay-7', 'reference' => 'R-7', 'room_number' => '101', 'currency' => 'KES', 'amount_minor' => 10000, 'reason' => 'not_in_house', 'status' => 'open'])->id);
        // The same payment reached a folio meanwhile (the sale synced again).
        $this->assertTrue($this->charge('102', 'pos.sale', 'pay-7', null)['posted']);

        $this->postJson("/api/v1/hotel/charge-exceptions/{$exception}/resolve", ['resolution' => 'moved', 'folio_id' => $other], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'exception_already_posted');
        $this->assertSame('open', $this->inTenant(fn () => ChargeException::find($exception)->status));
        $this->assertSame(1, $this->posLines());
    }

    // ---- 3: the night audit names its date ---------------------------------------------------------------------------------

    public function test_a_manual_audit_needs_its_date_and_a_day_not_over_needs_close_early(): void
    {
        $this->checkedIn(3, '101');
        // Two calls with no date audit nothing: the date is required.
        $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id], $this->headersFor())->assertStatus(422);
        $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id], $this->headersFor())->assertStatus(422);
        $this->assertSame($this->day(0), $this->inTenant(fn () => Property::query()->where('branch_id', $this->branchA->id)->first()->business_date->toDateString()));

        // The day is not over (the audit time is 03:00 the next day): refused unless closed early on purpose, which is audited.
        $body = ['branch_id' => $this->branchA->id, 'business_date' => $this->day(0)];
        $this->postJson('/api/v1/hotel/night-audits', $body, $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'audit_not_due');
        $this->assertSame(0, $this->audited('hotel.night_audit.close_early'));
        $this->postJson('/api/v1/hotel/night-audits', [...$body, 'close_early' => true], $this->headersFor())->assertCreated();
        $this->assertSame(1, $this->audited('hotel.night_audit.close_early'));
        // A double click on the same date answers the same audit and rolls nothing more.
        $this->postJson('/api/v1/hotel/night-audits', [...$body, 'close_early' => true], $this->headersFor())->assertOk();
        $this->assertSame($this->day(1), $this->inTenant(fn () => Property::query()->where('branch_id', $this->branchA->id)->first()->business_date->toDateString()));

        // Once its time has passed the date needs no flag.
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['night_audit_time' => '00:00']));
        $this->inTenant(fn () => Property::query()->where('branch_id', $this->branchA->id)->update(['business_date' => $this->day(-1)]));
        $this->postJson('/api/v1/hotel/night-audits', ['branch_id' => $this->branchA->id, 'business_date' => $this->day(-1)], $this->headersFor())->assertCreated();
    }

    // ---- 4: voids keep to a limit --------------------------------------------------------------------------------------------

    public function test_a_void_above_the_users_limit_is_refused_and_an_owner_has_none(): void
    {
        $folio = $this->folioOf($this->checkedIn(2, '101'));
        $manager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));
        // The Branch Manager's refund limit is KES 10,000.00 (RBAC-06): a void is held to it.
        $big = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 1500000, 'description' => 'Banquet'], $this->headersFor())->assertCreated()->json('data.id');
        $small = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 50000, 'description' => 'Parking'], $this->headersFor())->assertCreated()->json('data.id');

        $this->postJson("/api/v1/hotel/folios/{$folio}/lines/{$big}/void", ['reason' => 'Booked twice'], $this->headersFor($manager))->assertStatus(403)->assertJsonPath('code', 'void_above_limit');
        $this->postJson("/api/v1/hotel/folios/{$folio}/lines/{$small}/void", ['reason' => 'Mistake'], $this->headersFor($manager))->assertCreated();
        $this->postJson("/api/v1/hotel/folios/{$folio}/lines/{$big}/void", ['reason' => 'Booked twice'], $this->headersFor())->assertCreated();
    }

    // ---- 5: the audit trail ---------------------------------------------------------------------------------------------------

    public function test_manual_fees_and_room_charges_are_audited(): void
    {
        $stay = $this->checkedIn(2, '101');
        $folio = $this->folioOf($stay);
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['amount_minor' => 1000, 'description' => 'Parking'], $this->headersFor())->assertCreated();
        $this->assertSame(1, $this->audited('hotel.folio.charge'));

        $this->charge('101', 'restaurant.order', 'ord-5', null);
        $this->assertSame(1, $this->audited('hotel.folio.room_charge'));
        // A repeat posts and audits nothing more.
        $this->charge('101', 'restaurant.order', 'ord-5', null);
        $this->assertSame(1, $this->audited('hotel.folio.room_charge'));
        $this->inTenant(fn () => app(RoomCharges::class)->reverse('restaurant.order', 'ord-5'));
        $this->assertSame(1, $this->audited('hotel.folio.room_charge_reverse'));
    }

    // ---- 8: a Restaurant bill closed to a room -------------------------------------------------------------------------------

    public function test_a_restaurant_bill_closed_by_a_room_charge_is_posted_to_the_guests_folio_through_the_sale_events_only(): void
    {
        $this->ranges()->assertOk();
        $shift = $this->openShift();
        $folio = $this->folioOf($this->checkedIn(2, '101'));
        $bill = function (int $seq, string $folioId) use ($shift): array {
            $sale = $this->saleBody($shift, $seq, ['extensions' => ['restaurant' => ['order_id' => $this->id(), 'mode' => 'full', 'final' => true, 'line_ids' => []]]]);
            $sale['payments'] = [['id' => $this->id(), 'payment_method_id' => $this->roomCharge->id, 'currency' => 'KES', 'amount_minor' => $sale['totals']['total_minor'], 'amount_in_sale_minor' => $sale['totals']['total_minor'], 'rate' => null, 'status' => 'confirmed', 'tender' => ['room' => '101', 'folio_id' => $folioId]]];

            return $sale;
        };

        // The till names the guest's folio it showed at the table: the bill is charged to it, with the sale's reference.
        $this->upload([$bill(1, $folio)])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame(1, $this->posLines());

        // A bill whose folio is no longer the room's is held for the front desk, never put on whoever is in the room now.
        $this->upload([$bill(2, (string) Str::uuid7())])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $this->assertSame(1, $this->posLines());
        $this->assertSame(['folio_changed'], $this->inTenant(fn () => ChargeException::query()->pluck('reason')->all()));
    }

    // ---- 6: guest field rules -----------------------------------------------------------------------------------------------------

    public function test_a_field_rule_on_the_date_of_birth_or_the_address_hides_them(): void
    {
        $this->inTenant(fn () => $this->guest->forceFill(['date_of_birth' => '1990-04-02', 'address' => '1 Beach Road'])->save());
        $role = $this->inTenant(function () {
            $custom = $this->role('Desk without dob', ['hotel.guest.view', 'hotel.guest.manage', 'hotel.guest.identity']);
            FieldRule::create(['role_id' => $custom->id, 'resource' => 'hotel_guest', 'field' => 'date_of_birth', 'mode' => 'hidden']);
            FieldRule::create(['role_id' => $custom->id, 'resource' => 'hotel_guest', 'field' => 'address', 'mode' => 'hidden']);

            return $custom;
        });
        $user = $this->inTenant(function () use ($role) {
            $u = $this->colleague($this->owner);
            $this->assign($u, $role, Scope::company($this->acme->id));

            return $u;
        });

        $plain = $this->getJson("/api/v1/hotel/guests/{$this->guest->id}", $this->headersFor())->assertOk()->json('data');
        $this->assertSame(['1990-04-02', '1 Beach Road'], [$plain['date_of_birth'], $plain['address']]);
        $hidden = $this->getJson("/api/v1/hotel/guests/{$this->guest->id}", $this->headersFor($user))->assertOk()->json('data');
        $this->assertArrayNotHasKey('date_of_birth', $hidden);
        $this->assertArrayNotHasKey('address', $hidden);
    }

    // ---- 9: the charge uses the master-data price -------------------------------------------------------------------------------------

    public function test_an_extra_is_charged_at_the_price_list_price_and_another_price_needs_the_override_permission(): void
    {
        $this->inTenant(fn () => ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $this->minibar->id, 'uom_id' => $this->each->id, 'amount_minor' => '20000', 'currency' => 'KES', 'effective_from' => '2026-01-01']));
        $folio = $this->folioOf($this->checkedIn(2, '101'));
        $desk = $this->userWith('receptionist', Scope::branch($this->branchA->id));

        $this->getJson("/api/v1/hotel/item-price?folio_id={$folio}&item_id={$this->minibar->id}", $this->headersFor($desk))->assertOk()
            ->assertJsonPath('data.amount_minor', '20000')->assertJsonPath('data.tax_inclusive', true)->assertJsonPath('data.can_override', false);
        $this->getJson("/api/v1/hotel/item-price?folio_id={$folio}&item_id={$this->minibar->id}", $this->headersFor())->assertOk()->assertJsonPath('data.can_override', true);

        // With no price typed (or the same price) the list price is used, tax included as the list says.
        $line = $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'description' => 'Minibar'], $this->headersFor($desk))->assertCreated()->json('data');
        $this->assertSame(['20000', '17778', '2222'], [$line['amount_minor'], $line['net_minor'], $line['tax_minor']]);
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'unit_price_minor' => 20000], $this->headersFor($desk))->assertCreated();

        // Another price: refused without the permission, needs a reason with it, and is audited.
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'unit_price_minor' => 100, 'reason' => 'Discount'], $this->headersFor($desk))->assertForbidden()->assertJsonPath('code', 'price_override_forbidden');
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'unit_price_minor' => 100], $this->headersFor())->assertStatus(422);
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->minibar->id, 'unit_price_minor' => 15000, 'reason' => 'Loyal guest'], $this->headersFor())->assertCreated();
        $this->assertSame(1, $this->audited('hotel.folio.price_override'));

        // An item with no list price must be typed by someone allowed to.
        $this->postJson("/api/v1/hotel/folios/{$folio}/charges", ['item_id' => $this->roomItem->id], $this->headersFor($desk))->assertStatus(422)->assertJsonPath('code', 'price_required');
    }
}
