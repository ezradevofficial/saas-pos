<?php

namespace Modules\Salon\Tests\Concerns;

use App\Core\Identity\Models\User;
use App\Core\MasterData\Items\Item;
use App\Core\MasterData\Parties\Party;
use App\Core\MasterData\Prices\ItemPrice;
use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Modules\POS\Tests\Concerns\BuildsPos;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Models\Line;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Setting;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\StaffHour;
use Modules\Salon\Support\Catalogue;

/**
 * A tenant with POS and Salon active (BuildsPos' Acme in KES, branches A and B, a paired till at A's outlet), branch A as the
 * salon: services Haircut (KES 1,000.00, 30 min), Colour (KES 3,000.00, 90 min, needs a chair, 15 min clean-up) and Beard trim
 * (an add-on, KES 500.00, 15 min) with list prices; two stylists (Ann and Bob, each also a user with the Stylist role) working every day
 * 09:00 to 17:00 Nairobi time with a break 13:00 to 14:00; two chairs; a front desk (Receptionist), a manager (Salon manager) and
 * a branch manager; settings with a tip item, a fee item (20 % no-show fee, 10 % late cancellation inside 24 hours, no deposit) and a
 * five-haircut package. Time is frozen on a Wednesday at 08:00, so "today at 10:00" is in the future. Commission rules: Ann earns 20 %
 * of her services, everyone 10 % of products.
 */
trait BuildsSalon
{
    use BuildsPos;

    /** 2027-03-03 is a Wednesday. */
    protected string $today = '2027-03-03';

    protected Item $haircutItem;

    protected Item $colourItem;

    protected Item $beardItem;

    protected Item $tipItem;

    protected Item $feeItem;

    protected Item $packageItem;

    protected Service $haircut;

    protected Service $colour;

    protected Service $beard;

    protected PackageType $fiveCuts;

    protected Staff $ann;

    protected Staff $bob;

    protected User $annUser;

    protected User $bobUser;

    protected User $desk;

    protected User $manager;

    protected User $branchManager;

    protected Resource $chair1;

    protected Resource $chair2;

    protected Party $client;

    protected function setUpSalon(bool $inventory = false, bool $rules = true): void
    {
        $this->travelTo(CarbonImmutable::parse($this->today.' 08:00:00', 'Africa/Nairobi'));
        $this->setUpPos();

        $this->inTenant(function () use ($inventory) {
            app(ModuleRegistry::class)->activate('salon');
            $inventory && app(ModuleRegistry::class)->activate('inventory');

            $make = fn (string $code, string $name, int $price) => tap(Item::create(['code' => $code, 'name' => $name, 'type' => 'service', 'base_uom_id' => $this->each->id, 'tax_category_id' => $this->goods->id]), function (Item $item) use ($price) {
                ItemPrice::create(['price_list_id' => $this->retail->id, 'item_id' => $item->id, 'uom_id' => $this->each->id, 'amount_minor' => (string) $price, 'currency' => 'KES', 'effective_from' => '2026-01-01']);
            });
            $this->haircutItem = $make('HAIRCUT', 'Haircut', 100000);
            $this->colourItem = $make('COLOUR', 'Colour', 300000);
            $this->beardItem = $make('BEARD', 'Beard trim', 50000);
            $this->tipItem = $make('TIP', 'Tip', 10000);
            $this->feeItem = $make('FEE', 'Cancellation fee', 10000);
            $this->packageItem = $make('PKG5', 'Five haircuts', 450000);

            $this->chair1 = Resource::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Chair 1', 'kind' => 'chair']);
            $this->chair2 = Resource::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Chair 2', 'kind' => 'chair']);
        });

        $this->annUser = $this->userWith('stylist', Scope::branch($this->branchA->id));
        $this->bobUser = $this->userWith('stylist', Scope::branch($this->branchA->id));
        $this->desk = $this->userWith('salon_receptionist', Scope::branch($this->branchA->id));
        $this->manager = $this->userWith('salon_manager', Scope::branch($this->branchA->id));
        $this->branchManager = $this->userWith('branch_manager', Scope::branch($this->branchA->id));

        $this->inTenant(function () use ($rules) {
            $this->ann = Staff::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'user_id' => $this->annUser->id, 'name' => 'Ann', 'title' => 'Senior stylist', 'color' => 'c1']);
            $this->bob = Staff::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'user_id' => $this->bobUser->id, 'name' => 'Bob', 'title' => 'Barber', 'color' => 'c2']);

            foreach ([$this->ann, $this->bob] as $staff) {
                foreach (range(1, 7) as $weekday) {
                    StaffHour::create(['staff_id' => $staff->id, 'branch_id' => $this->branchA->id, 'weekday' => $weekday, 'kind' => 'work', 'starts_at' => '09:00', 'ends_at' => '17:00']);
                    StaffHour::create(['staff_id' => $staff->id, 'branch_id' => $this->branchA->id, 'weekday' => $weekday, 'kind' => 'break', 'starts_at' => '13:00', 'ends_at' => '14:00']);
                }
            }

            $this->haircut = Service::create(['company_id' => $this->acme->id, 'item_id' => $this->haircutItem->id, 'name' => 'Haircut', 'category' => 'Hair', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'online_bookable' => true, 'addon_ids' => []]);
            $this->beard = Service::create(['company_id' => $this->acme->id, 'item_id' => $this->beardItem->id, 'name' => 'Beard trim', 'category' => 'Hair', 'kind' => 'addon', 'duration_minutes' => 15, 'buffer_minutes' => 0, 'online_bookable' => true]);
            $this->colour = Service::create(['company_id' => $this->acme->id, 'item_id' => $this->colourItem->id, 'name' => 'Colour', 'category' => 'Hair', 'duration_minutes' => 90, 'buffer_minutes' => 15, 'online_bookable' => true, 'staff_ids' => [$this->ann->id], 'resource_ids' => [$this->chair1->id, $this->chair2->id]]);
            $this->haircut->forceFill(['addon_ids' => [$this->beard->id]])->save();
            $this->fiveCuts = PackageType::create(['company_id' => $this->acme->id, 'item_id' => $this->packageItem->id, 'name' => 'Five haircuts', 'sessions' => 5, 'validity_days' => 180, 'service_ids' => [$this->haircut->id]]);

            Setting::create(['company_id' => $this->acme->id, 'tip_item_id' => $this->tipItem->id, 'fee_item_id' => $this->feeItem->id, 'late_cancel_fee_percent' => '10', 'no_show_fee_percent' => '20', 'cancel_window_hours' => 24, 'commission_item_code' => 'COMMISSION']);
            SalonBranch::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'public_code' => 'testcode0001', 'online_enabled' => true]);
            app(Catalogue::class)->creditMethod($this->acme->id);

            if ($rules) {
                CommissionRule::create(['company_id' => $this->acme->id, 'staff_id' => $this->ann->id, 'applies_to' => 'service', 'kind' => 'percent', 'rate' => '20', 'effective_from' => '2026-01-01']);
                CommissionRule::create(['company_id' => $this->acme->id, 'applies_to' => 'product', 'kind' => 'percent', 'rate' => '10', 'effective_from' => '2026-01-01']);
            }

            $this->client = Party::create(['kind' => 'person', 'name' => 'Amina Client', 'roles' => ['customer'], 'company_id' => $this->acme->id, 'phones' => [['number' => '+254700111222', 'label' => 'mobile']]]);
        });
    }

    /** Moves the clock to $time today (Nairobi). People sign in again: a token does not outlive the idle limit of the time it was made. */
    protected function clockAt(string $time, int $days = 0): void
    {
        $this->travelTo(CarbonImmutable::parse($this->today.' '.$time.':00', 'Africa/Nairobi')->addDays($days));
        $this->organisationTokens = [];
    }

    protected function api(string $method, string $uri, array $body = [], ?User $user = null): TestResponse
    {
        return $this->json($method, '/api/v1/'.$uri, $body, $this->headersFor($user));
    }

    /** UTC ISO time for $time ("10:00") on today (or $days from today) in the salon's Nairobi time. */
    protected function at(string $time, int $days = 0): string
    {
        return CarbonImmutable::parse($this->today.' '.$time.':00', 'Africa/Nairobi')->addDays($days)->utc()->toIso8601String();
    }

    /** @param list<array<string, mixed>> $lines */
    protected function bookingBody(array $lines, string $time = '10:00', array $overrides = []): array
    {
        return array_replace([
            'branch_id' => $this->branchA->id, 'salon_client_id' => $this->client->id, 'starts_at' => $this->at($time),
            'lines' => $lines,
        ], $overrides);
    }

    /** Books Ann's haircut at $time through the API as the front desk and returns the appointment's data. */
    protected function book(string $time = '10:00', ?Staff $staff = null, array $overrides = [], ?Service $service = null): array
    {
        return $this->api('POST', 'salon/appointments', $this->bookingBody([['service_id' => ($service ?? $this->haircut)->id, 'staff_id' => ($staff ?? $this->ann)->id]], $time, $overrides), $this->desk)
            ->assertCreated()->json('data');
    }

    protected function appointment(string $id): Appointment
    {
        return $this->inTenant(fn () => Appointment::query()->findOrFail($id));
    }

    /** A till sale line for a service item at its list price (tax included at the test rate). */
    protected function serviceLine(Item $item, int $price, array $overrides = []): array
    {
        $tax = (int) round($price * 0.125 / 1.125);

        return $this->line(array_replace([
            'item_id' => $item->id, 'qty' => '1', 'unit_price_minor' => (string) $price, 'list_price_minor' => (string) $price, 'tax_minor' => (string) $tax, 'total_minor' => (string) $price,
        ], $overrides));
    }

    /**
     * The till's sale for an appointment: its service lines at list price, paid in cash for $cashier (a stylist or the desk) unless
     * $payments are given, with `extensions.salon` naming the appointment. $extra lines (a tip, a product) are appended.
     *
     * @param  list<array<string, mixed>>  $extra
     * @param  list<array<string, mixed>>|null  $payments
     */
    protected function salonSale(string $appointmentId, int $seq, User $cashier, array $extra = [], ?array $payments = null, array $ext = [], array $overrides = []): array
    {
        $appointment = $this->appointment($appointmentId);
        $lines = $this->inTenant(fn () => Line::query()->where('appointment_id', $appointmentId)->orderBy('position')->get()
            ->map(fn ($l) => $this->serviceLine(Item::query()->findOrFail($l->item_id), (int) $l->price_minor))->all());
        $lines = $overrides['lines'] ?? [...$lines, ...$extra];
        $total = (string) array_sum(array_map(fn (array $l) => (int) $l['total_minor'], $lines));

        $body = $this->saleBody($this->tillShift ?? $this->shiftFor($cashier), $seq, [
            'cashier_id' => $cashier->id, 'actor_proof' => $this->actorProof($cashier->id), 'customer_id' => $appointment->client_id, 'lines' => $lines,
            'extensions' => ['salon' => array_replace(['appointment_id' => $appointmentId], $ext)], ...$overrides,
        ]);
        $body['payments'] = $payments ?? [[
            'id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total, 'rate' => null, 'status' => 'confirmed',
        ]];

        return $body;
    }

    protected ?string $tillShift = null;

    /** A sale of the five-haircut package item to $customerId (none: a walk-in with no client). */
    protected function packageSale(int $seq, ?string $customerId, int $price = 450000): array
    {
        return $this->saleBody($this->tillShift ?? $this->shiftFor($this->desk), $seq, [
            'cashier_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'customer_id' => $customerId, 'lines' => [$this->serviceLine($this->packageItem, $price)],
        ]);
    }

    protected function shiftFor(User $cashier): string
    {
        $this->ranges('pos.receipt')->assertOk();

        return $this->tillShift ??= $this->openShift(null, $this->owner->id);
    }

    /** The `salon_credit` payment method of the company. */
    protected function creditMethodId(): string
    {
        return $this->inTenant(fn () => app(Catalogue::class)->creditMethod($this->acme->id)->id);
    }

    /** A `salon_credit` payment of $amount for the sale (a deposit or a package session). */
    protected function creditPayment(string $amount, array $tender): array
    {
        return ['id' => $this->id(), 'payment_method_id' => $this->creditMethodId(), 'currency' => 'KES', 'amount_minor' => $amount, 'amount_in_sale_minor' => $amount, 'rate' => null, 'status' => 'confirmed', 'tender' => $tender];
    }

    protected function cashPayment(string $amount): array
    {
        return ['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $amount, 'amount_in_sale_minor' => $amount, 'rate' => null, 'status' => 'confirmed'];
    }

    /** Another tenant with the Salon module active, so only isolation (not the module gate) refuses it. */
    protected function otherSalonTenant(): array
    {
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('salon'));

        return $other;
    }

    /** Uploads sales at the main till and asserts they were stored. */
    protected function sell(array ...$sales): TestResponse
    {
        return $this->upload($sales)->assertOk();
    }

    /** Voids a stored sale through the POS void upload, with the branch manager's override. */
    protected function voidSale(array $sale, ?User $by = null): void
    {
        $by ??= $this->desk;
        $this->postJson('/api/v1/pos/voids', ['voids' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'voided_by_id' => $by->id, 'voided_at' => now()->toIso8601String(), 'reason' => 'Wrong client',
            'actor_proof' => $this->actorProof($by->id), 'override' => $this->override($this->branchManager->id),
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.void_status', 'applied');
    }

    /** Refunds $qty of the sale's line number $line (0-based), $total minor units, in cash, with the branch manager's override. */
    protected function refundLine(array $sale, int $line, string $qty, string $total, int $seq = 1): void
    {
        $this->ranges('pos.refund')->assertOk();
        $by = $this->desk;
        $this->postJson('/api/v1/pos/refunds', ['refunds' => [[
            'id' => $this->id(), 'sale_id' => $sale['id'], 'shift_id' => $this->tillShift, 'cashier_id' => $by->id, 'actor_proof' => $this->actorProof($by->id),
            'receipt_seq' => $seq, 'receipt_number' => sprintf('RF-L01-%06d', $seq), 'refunded_at' => now()->toIso8601String(), 'reason' => 'Unhappy', 'total_minor' => $total,
            'lines' => [['id' => $this->id(), 'sale_line_id' => $sale['lines'][$line]['id'], 'qty' => $qty]],
            'payments' => [['id' => $this->id(), 'payment_method_id' => $this->methods['cash_kes']->id, 'currency' => 'KES', 'amount_minor' => $total, 'amount_in_sale_minor' => $total]],
            'override' => $this->override($this->branchManager->id),
        ]]], $this->tillHeaders())->assertOk()->assertJsonPath('results.0.status', 'stored');
    }

    /** A completed visit: booked, served and finished by its stylist; returns the appointment data. */
    protected function finished(string $time = '10:00', ?Staff $staff = null, array $overrides = []): array
    {
        $made = $this->book($time, $staff, $overrides);
        $user = ($staff ?? $this->ann)->user_id === $this->bobUser->id ? $this->bobUser : $this->annUser;

        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made['id']}/{$step}", [], $user)->assertOk();
        }

        return $made;
    }
}
