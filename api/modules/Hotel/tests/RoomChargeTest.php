<?php

namespace Modules\Hotel\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Sync\DeviceScope;
use Modules\Hotel\Contracts\RoomCharges;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Pos\PosCharges;
use Modules\Hotel\Sync\InHouseSource;
use Modules\Hotel\Tests\Concerns\BuildsHotel;
use Modules\POS\Events\SaleSnapshot;
use Modules\POS\Models\Sale;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// HOT-22, HOT-23, HOT-35: charge to room from a till, and the contract Restaurant uses: validation, idempotency, voids and refunds, exceptions.
class RoomChargeTest extends TestCase
{
    use BuildsHotel, RefreshTenantDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHotel();
        $this->ranges()->assertOk();
        $this->shift = $this->openShift();
    }

    /** A KES sale of one soap line (KES 1,125.00) paid entirely to room $room. */
    private function chargedSale(int $seq, string $room, array $over = []): array
    {
        $sale = $this->saleBody($this->shift, $seq, $over);
        $sale['payments'] = [[
            'id' => $this->id(), 'payment_method_id' => $this->roomCharge->id, 'currency' => 'KES', 'amount_minor' => $sale['totals']['total_minor'],
            'amount_in_sale_minor' => $sale['totals']['total_minor'], 'rate' => null, 'status' => 'confirmed', 'tender' => ['room' => $room],
        ]];

        return $sale;
    }

    private function lines(string $folio): array
    {
        return $this->inTenant(fn () => FolioLine::query()->where('folio_id', $folio)->where('kind', 'pos_charge')->get()->all());
    }

    private function folioOf(array $stay): string
    {
        return $this->inTenant(fn () => Folio::query()->where('stay_id', $stay['id'])->value('id'));
    }

    public function test_a_sale_paid_by_room_charge_is_posted_to_the_in_house_guests_folio_once(): void
    {
        $stay = $this->checkedIn(2, '101');
        $folio = $this->folioOf($stay);
        $sale = $this->chargedSale(1, '101');

        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        // A retry of the upload stores and posts nothing more.
        $this->upload([$sale])->assertOk();

        $lines = $this->lines($folio);
        $this->assertCount(1, $lines);
        $this->assertSame([112500, 'pos.sale', $sale['payments'][0]['id'], 'R-L01-000001'], [$lines[0]->amount_minor, $lines[0]->source, $lines[0]->source_id, $lines[0]->reference]);
        $this->assertSame('112500', $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.balance_minor'));

        // It is on the folio as settled by room: a till receipt already exists, so it is not on the hotel invoice.
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", ['payments' => [['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => 112500]]], $this->headersFor())->assertOk()->assertJsonPath('data.folio.invoice_number', null);
    }

    public function test_the_till_is_told_when_the_room_cannot_be_charged_and_the_sale_is_kept_for_the_front_desk(): void
    {
        $stay = $this->checkedIn(1, '101');
        $this->postJson("/api/v1/hotel/stays/{$stay['id']}/check-out", [], $this->headersFor())->assertOk();

        $sale = $this->chargedSale(1, '101');
        $this->upload([$sale])->assertOk()->assertJsonPath('results.0.status', 'stored');
        $flags = $this->inTenant(fn () => Sale::query()->whereKey($sale['id'])->first()->flags);
        $this->assertSame(['tender_flagged', 'room_charge_not_in_house'], [$flags[0]['code'], $flags[0]['detail']['code']]);

        $exception = $this->inTenant(fn () => ChargeException::query()->sole());
        $this->assertSame(['open', 'not_in_house', 112500, '101'], [$exception->status, $exception->reason, $exception->amount_minor, $exception->room_number]);

        // An unknown room is an exception too; another property's room number is not found here.
        $this->upload([$this->chargedSale(2, '999')])->assertOk();
        $this->assertSame(['not_in_house', 'room_unknown'], $this->inTenant(fn () => ChargeException::query()->orderBy('created_at')->pluck('reason')->all()));
    }

    public function test_the_front_desk_moves_an_exception_to_a_folio_or_closes_it(): void
    {
        $leaver = $this->checkedIn(1, '101');
        $this->postJson("/api/v1/hotel/stays/{$leaver['id']}/check-out", [], $this->headersFor())->assertOk();
        $this->upload([$this->chargedSale(1, '101')])->assertOk();
        $other = $this->folioOf($this->checkedIn(2, '102'));
        $id = $this->inTenant(fn () => ChargeException::query()->sole()->id);

        $this->getJson('/api/v1/hotel/charge-exceptions', $this->headersFor())->assertOk()->assertJsonCount(1, 'data');
        $this->postJson("/api/v1/hotel/charge-exceptions/{$id}/resolve", ['resolution' => 'moved'], $this->headersFor())->assertStatus(422);
        $this->postJson("/api/v1/hotel/charge-exceptions/{$id}/resolve", ['resolution' => 'moved', 'folio_id' => $other], $this->headersFor())->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->assertCount(1, $this->lines($other));
        $this->postJson("/api/v1/hotel/charge-exceptions/{$id}/resolve", ['resolution' => 'written_off', 'note' => 'x2'], $this->headersFor())->assertStatus(409)->assertJsonPath('code', 'exception_resolved');
        $this->getJson('/api/v1/hotel/charge-exceptions', $this->headersFor())->assertOk()->assertJsonCount(0, 'data');
        $this->assertGreaterThan(0, $this->audited('hotel.charge_exception.resolve'));
    }

    public function test_a_voided_sale_takes_its_room_charge_back_and_a_refund_credits_the_folio(): void
    {
        $folio = $this->folioOf($this->checkedIn(2, '101'));
        $sale = $this->chargedSale(1, '101');
        $this->upload([$sale])->assertOk();
        $snapshot = $this->inTenant(fn () => SaleSnapshot::sale(Sale::query()->findOrFail($sale['id'])));

        $this->inTenant(function () use ($snapshot) {
            app(PosCharges::class)->voided($snapshot);
            app(PosCharges::class)->voided($snapshot);
        });
        $this->assertSame('0', $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.balance_minor'));
        $this->assertCount(1, $this->lines($folio));

        // A refund of 300.00 on another sale is a credit line on the folio, once.
        $second = $this->chargedSale(2, '101');
        $this->upload([$second])->assertOk();
        $refund = ['id' => $this->id(), 'branch_id' => $this->branchA->id, 'company_id' => $this->acme->id, 'receipt_number' => 'RF-1',
            'payments' => [['id' => $this->id(), 'method_type' => 'room_charge', 'currency' => 'KES', 'amount_minor' => '30000', 'tender' => ['room' => '101']]]];
        $this->inTenant(function () use ($refund) {
            app(PosCharges::class)->refunded($refund);
            app(PosCharges::class)->refunded($refund);
        });
        $this->assertSame('82500', $this->getJson("/api/v1/hotel/folios/{$folio}", $this->headersFor())->json('data.balance_minor'));
    }

    public function test_the_contract_validates_the_room_the_currency_the_limit_and_the_tenant(): void
    {
        $stay = $this->checkedIn(2, '101');
        $folio = $this->folioOf($stay);
        $this->inTenant(fn () => Settings::query()->where('company_id', $this->acme->id)->update(['charge_limit_minor' => 200000]));

        $this->inTenant(function () use ($folio) {
            $rooms = app(RoomCharges::class);
            $ok = $rooms->lookup($this->branchA->id, ' 101 ', 'KES', 100000);
            $this->assertSame([true, '101', 'Amina Otieno', $folio], [$ok['allowed'], $ok['room_number'], $ok['guest_name'], $ok['folio_id']]);
            $this->assertSame('currency_mismatch', $rooms->lookup($this->branchA->id, '101', 'USD', 100)['reason']);
            $this->assertSame('limit_exceeded', $rooms->lookup($this->branchA->id, '101', 'KES', 250000)['reason']);
            $this->assertSame('not_in_house', $rooms->lookup($this->branchA->id, '102', 'KES', 100)['reason']);
            $this->assertSame('room_unknown', $rooms->lookup($this->branchA->id, '555')['reason']);
            // Another property of the same tenant does not have the room.
            $this->assertSame('room_unknown', $rooms->lookup($this->branchB->id, '101')['reason']);

            $first = $rooms->post(['branch_id' => $this->branchA->id, 'room_number' => '101', 'amount_minor' => 150000, 'currency' => 'KES', 'source' => 'restaurant.order', 'source_id' => 'ord-1', 'reference' => 'T12']);
            $this->assertTrue($first['posted']);
            $repeat = $rooms->post(['branch_id' => $this->branchA->id, 'room_number' => '101', 'amount_minor' => 150000, 'currency' => 'KES', 'source' => 'restaurant.order', 'source_id' => 'ord-1']);
            $this->assertSame([false, true, $first['line_id']], [$repeat['posted'], $repeat['duplicate'], $repeat['line_id']]);
            // 150,000 already owed: another 100,000 passes the limit of 200,000? No.
            $this->assertSame('limit_exceeded', $rooms->post(['branch_id' => $this->branchA->id, 'room_number' => '101', 'amount_minor' => 100000, 'currency' => 'KES', 'source' => 'restaurant.order', 'source_id' => 'ord-2'])['reason']);
            $this->assertTrue($rooms->reverse('restaurant.order', 'ord-1'));
            $this->assertFalse($rooms->reverse('restaurant.order', 'ord-1'));
        });
    }

    public function test_another_tenants_room_is_never_charged_and_the_contract_is_closed_while_the_module_is_off(): void
    {
        $other = $this->otherTenant();
        $this->checkedIn(1, '101');
        // The other tenant has no hotel and no such room: nothing reaches it, and its branch is not even visible here.
        $this->inTenant(function () use ($other) {
            $this->assertSame('room_unknown', app(RoomCharges::class)->lookup($other['branch']->id, '101')['reason']);
        });
        $this->inTenant(function () {
            app(ModuleRegistry::class)->deactivate('hotel');
            $this->assertSame('module_inactive', app(RoomCharges::class)->lookup($this->branchA->id, '101')['reason']);
            $this->assertFalse(app(RoomCharges::class)->available());
        });
    }

    public function test_the_till_asks_online_and_the_in_house_list_is_synced_without_personal_data(): void
    {
        $this->checkedIn(1, '101');
        $this->postJson('/api/v1/hotel/device/room-lookup', ['room' => '101', 'currency' => 'KES', 'amount_minor' => 5000], $this->tillHeaders())->assertOk()
            ->assertJsonPath('data.allowed', true)->assertJsonPath('data.guest_name', 'Amina Otieno')->assertJsonPath('data.room_number', '101');
        $this->postJson('/api/v1/hotel/device/room-lookup', ['room' => '102'], $this->tillHeaders())->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.reason', 'not_in_house');
        // A person's token is not a till's.
        $this->postJson('/api/v1/hotel/device/room-lookup', ['room' => '101'], $this->headersFor())->assertForbidden();

        $rows = $this->inTenant(fn () => app(InHouseSource::class)->rows(DeviceScope::of($this->till)));
        $this->assertCount(1, $rows);
        $this->assertSame(['room_number', 'guest_name', 'folio_id', 'currency', 'allowed'], array_values(array_intersect(array_keys($rows[0]), ['room_number', 'guest_name', 'folio_id', 'currency', 'allowed'])));
        $this->assertStringNotContainsString('12345678', json_encode($rows));
        $this->assertStringNotContainsString('254700000001', json_encode($rows));
    }
}
