<?php

namespace Modules\Hotel\Tests\Concerns;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\PaymentMethods\PaymentMethod;
use App\Core\Rbac\ModuleRegistry;
use Carbon\CarbonImmutable;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Settings;
use Modules\Hotel\Support\Properties;
use Modules\POS\Tests\Concerns\BuildsPos;

/**
 * A tenant with the POS and Hotel modules active (BuildsPos' Acme in KES, branches A and B, a paired till at A's outlet), branch A as the
 * property: a Deluxe room type (sleeps 3, 1 child), rooms 101, 102 and 103 on floor 1, a bed-and-breakfast plan at KES 10,000.00 on weekdays
 * and KES 12,000.00 on weekends (Friday and Saturday), tax included, a room-night service item in the test VAT category (12.5 %, a synthetic
 * figure), a room-charge payment method and a guest. Time is frozen by the test at a Wednesday, so the business date is known.
 */
trait BuildsHotel
{
    use BuildsPos;

    protected RoomType $deluxe;

    /** @var array<string, Room> by number */
    protected array $rooms = [];

    protected RatePlan $bb;

    protected Item $roomItem;

    protected Item $minibar;

    protected PaymentMethod $roomCharge;

    protected Guest $guest;

    /** 2027-03-03 is a Wednesday. */
    protected string $today = '2027-03-03';

    protected function setUpHotel(bool $pos = true): void
    {
        $this->travelTo(CarbonImmutable::parse($this->today.' 09:00:00', 'Africa/Nairobi'));
        $pos && $this->setUpPos();

        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('hotel');
            $this->roomItem = Item::create(['code' => 'ROOM', 'name' => 'Room night', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
            $this->minibar = Item::create(['code' => 'MINI', 'name' => 'Minibar', 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]);
            Settings::create(['company_id' => $this->acme->id, ...Settings::defaults(), 'room_item_id' => $this->roomItem->id, 'require_guest_id' => false]);

            $this->deluxe = RoomType::create(['company_id' => $this->acme->id, 'code' => 'DLX', 'name' => 'Deluxe', 'base_adults' => 2, 'max_adults' => 3, 'max_children' => 1, 'max_occupancy' => 3]);

            foreach (['101', '102', '103'] as $number) {
                $this->rooms[$number] = Room::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'room_type_id' => $this->deluxe->id, 'number' => $number, 'floor' => '1', 'status' => Room::VACANT_CLEAN]);
            }

            $this->bb = RatePlan::create([
                'company_id' => $this->acme->id, 'room_type_id' => $this->deluxe->id, 'code' => 'BB', 'name' => 'Bed and breakfast', 'board' => 'bed_breakfast', 'currency' => 'KES',
                'weekday_rate_minor' => 1000000, 'weekend_rate_minor' => 1200000, 'extra_adult_minor' => 200000, 'child_rate_minor' => 100000, 'free_children' => 0, 'tax_inclusive' => true,
            ]);
            $this->roomCharge = PaymentMethod::create(['company_id' => $this->acme->id, 'type' => 'room_charge', 'name' => 'Room charge', 'currency' => 'KES', 'active' => true, 'position' => 9]);
            $this->guest = Guest::create(['company_id' => $this->acme->id, 'party_id' => $this->customer($this->acme->id)->id, 'name' => 'Amina Otieno', 'phone' => '+254700000001', 'id_type' => 'national_id', 'id_number' => '12345678']);
            // The property's business date is today.
            app(Properties::class)->ensure($this->branchA->id);
        });
    }

    /** How many audit entries of $action the tenant has (AUD-01). */
    protected function audited(string $action): int
    {
        return $this->inTenant(fn () => AuditEntry::query()->where('action', $action)->count());
    }

    /** A manual night audit of the property's current business date, closed on purpose before its time (HOT-30). */
    protected function auditBody(array $over = []): array
    {
        return [
            'branch_id' => $this->branchA->id,
            'business_date' => $this->inTenant(fn () => app(Properties::class)->businessDate($this->branchA->id)),
            'close_early' => true,
            ...$over,
        ];
    }

    protected function day(int $offset = 0): string
    {
        return CarbonImmutable::parse($this->today)->addDays($offset)->toDateString();
    }

    /** The body of a one-room direct booking from today for $nights. */
    protected function bookingBody(int $nights = 2, array $stay = [], array $over = []): array
    {
        return array_replace([
            'branch_id' => $this->branchA->id,
            'guest_id' => $this->guest->id,
            'stays' => [array_replace(['room_type_id' => $this->deluxe->id, 'rate_plan_id' => $this->bb->id, 'arrival_on' => $this->day(0), 'departure_on' => $this->day($nights), 'adults' => 2], $stay)],
        ], $over);
    }

    /** Book through the API as the owner and return the reservation data. */
    protected function book(int $nights = 2, array $stay = [], array $over = []): array
    {
        return $this->postJson('/api/v1/hotel/reservations', $this->bookingBody($nights, $stay, $over), $this->headersFor())->assertCreated()->json('data');
    }

    /** A booking checked in to $room through the API; returns the stay data. */
    protected function checkedIn(int $nights = 2, string $room = '101', array $stay = []): array
    {
        $reservation = $this->book($nights, [...['arrival_on' => $this->day(0)], ...$stay]);
        $stayId = $reservation['stays'][0]['id'];

        return $this->postJson("/api/v1/hotel/stays/{$stayId}/check-in", ['room_id' => $this->rooms[$room]->id], $this->headersFor())->assertOk()->json('data');
    }
}
