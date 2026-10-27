<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\Notifications\EventTypes;
use App\Core\Notifications\Models\NotificationDelivery;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Ecommerce\Models\Customer;
use Modules\Ecommerce\Models\CustomerSession;
use Modules\Ecommerce\Models\Store;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Models\SalonBranch;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-14, SAL-15, SAL-26: the public booking page (no account, rate-limited, minimal personal data), reminders and the guest purge.
class OnlineBookingTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private const CODE = 'testcode0001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpSalon();
        \Illuminate\Support\Facades\Cache::flush();
        // Five writes a minute is the real limit; the tests that are not about it book more than that.
        config(['salon.booking.writes_per_ip_per_minute' => 100]);
    }

    private function page(string $path = '', array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/salon-booking/'.self::CODE.$path.($query === [] ? '' : '?'.http_build_query($query)), ['Accept' => 'application/json']);
    }

    private function reserve(array $body, array $headers = [], string $path = '/appointments'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/salon-booking/'.self::CODE.$path, $body, ['Accept' => 'application/json', ...$headers]);
    }

    private function booking(string $time = '10:00', array $overrides = []): array
    {
        return array_replace(['guest_name' => 'Wendy Walker', 'guest_phone' => '0712 345 678', 'service_ids' => [$this->haircut->id], 'starts_at' => $this->at($time)], $overrides);
    }

    public function test_the_page_shows_what_is_for_sale_and_nothing_about_people(): void
    {
        $menu = $this->page()->assertOk()->json('data');
        $this->assertSame(['Branch A', 'KES'], [$menu['name'], $menu['currency']]);
        $this->assertEqualsCanonicalizing(['Haircut', 'Beard trim', 'Colour'], array_column($menu['services'], 'name'));
        $this->assertSame('100000', collect($menu['services'])->firstWhere('name', 'Haircut')['price_minor']);
        $this->assertEqualsCanonicalizing(['Ann', 'Bob'], array_column($menu['staff'], 'name'));
        $this->assertArrayNotHasKey('user_id', $menu['staff'][0]);

        // A service or a stylist that is not put online is not offered.
        $this->inTenant(function () {
            $this->colour->forceFill(['online_bookable' => false])->save();
            $this->bob->forceFill(['accepts_online' => false])->save();
        });
        $menu = $this->page()->assertOk()->json('data');
        $this->assertNotContains('Colour', array_column($menu['services'], 'name'));
        $this->assertSame(['Ann'], array_column($menu['staff'], 'name'));
    }

    public function test_an_unknown_code_a_switched_off_page_and_a_tenant_without_the_module_are_all_not_found(): void
    {
        $this->getJson('/api/v1/salon-booking/doesnotexist1')->assertNotFound();
        $this->getJson('/api/v1/salon-booking/short')->assertNotFound();
        $this->inTenant(fn () => SalonBranch::query()->update(['online_enabled' => false]));
        $this->page()->assertNotFound();
        $this->inTenant(fn () => SalonBranch::query()->update(['online_enabled' => true]));
        $this->page()->assertOk();
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('salon'));
        $this->page()->assertNotFound();
    }

    public function test_open_times_respect_the_lead_time_and_the_horizon(): void
    {
        $slots = $this->page('/slots', ['service_ids' => [$this->haircut->id], 'date' => $this->today])->assertOk()->json('data');
        $starts = array_column($slots, 'starts_at');
        $this->assertContains($this->at('09:00'), $starts, 'the page opens an hour ahead: 09:00 is on offer at 08:00');
        $this->assertNotContains($this->at('13:00'), $starts);
        $this->assertSame(['starts_at', 'ends_at'], array_keys($slots[0]));
        $this->clockAt('08:30');
        $starts = array_column($this->page('/slots', ['service_ids' => [$this->haircut->id], 'date' => $this->today])->json('data'), 'starts_at');
        $this->assertNotContains($this->at('09:00'), $starts);
        $this->assertContains($this->at('09:30'), $starts);
        // Beyond the horizon nothing is offered.
        $this->assertSame([], $this->page('/slots', ['service_ids' => [$this->haircut->id], 'date' => '2027-12-01'])->json('data'));
        $this->page('/slots', ['date' => $this->today])->assertUnprocessable();
    }

    public function test_a_visitor_books_with_a_name_and_a_phone_and_only_that_is_kept(): void
    {
        $made = $this->reserve($this->booking())->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^SAL-A-2027-\d{5}$/', $made['number']);
        $this->assertSame(['booked', ['Haircut'], '100000'], [$made['status'], $made['services'], $made['total_minor']]);
        // What comes back is what the visitor gave and chose: no ids, no staff, no one else.
        $this->assertSame([], array_values(array_intersect(array_keys($made), ['id', 'staff_id', 'client_id', 'guest_name', 'guest_phone', 'notes'])));

        $row = $this->inTenant(fn () => Appointment::query()->sole());
        $this->assertSame(['online', null, 'Wendy Walker', '0712 345 678', null], [$row->source, $row->client_id, $row->guest_name, $row->guest_phone, $row->guest_email]);
        // The audit log names the booking, never the visitor.
        $this->assertStringNotContainsString('Wendy', json_encode(AuditEntry::where('auditable_id', $row->id)->get()->map->only(['before', 'after'])->all()));
        $this->assertStringNotContainsString('0712', json_encode(AuditEntry::where('auditable_id', $row->id)->get()->map->only(['before', 'after'])->all()));

        // The stylist the page picked is free-form ("anyone"): Ann or Bob, both qualified, are fine; the same time again goes to the other.
        $this->reserve($this->booking('10:00', ['guest_name' => 'Second', 'guest_phone' => '0722000111']))->assertCreated();
        $this->reserve($this->booking('10:00', ['guest_name' => 'Third', 'guest_phone' => '0722000222']))->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertEqualsCanonicalizing([$this->ann->id, $this->bob->id], $this->inTenant(fn () => \Modules\Salon\Models\Line::query()->pluck('staff_id')->all()));
    }

    public function test_a_booking_needs_a_way_to_reach_the_visitor_a_slot_on_the_grid_and_a_stylist_who_takes_online_bookings(): void
    {
        $this->reserve($this->booking('10:00', ['guest_phone' => null]))->assertStatus(422)->assertJsonPath('code', 'contact_required');
        $this->reserve($this->booking('10:00', ['guest_phone' => null, 'guest_email' => 'wendy@example.com']))->assertCreated();
        $this->reserve($this->booking('11:07'))->assertStatus(422)->assertJsonPath('code', 'slot_unavailable');
        $this->clockAt('09:30');
        $this->reserve($this->booking('10:00', ['guest_phone' => '0733000111']))->assertStatus(422)->assertJsonPath('code', 'slot_unavailable');
        $this->reserve($this->booking('11:00', ['guest_phone' => '0733000111', 'service_ids' => [$this->id()]]))->assertStatus(422)->assertJsonPath('code', 'service_unknown');
        $this->inTenant(fn () => $this->ann->forceFill(['accepts_online' => false])->save());
        $this->reserve($this->booking('11:00', ['guest_phone' => '0733000111', 'staff_id' => $this->ann->id]))->assertStatus(422)->assertJsonPath('code', 'staff_unqualified');
        // Nothing in the body can set a price, a status or a client.
        $made = $this->reserve([...$this->booking('11:00', ['guest_phone' => '0733000111']), 'total_minor' => '1', 'status' => 'paid', 'client_id' => $this->client->id])->assertCreated()->json('data');
        $this->assertSame(['booked', '100000'], [$made['status'], $made['total_minor']]);
        $this->assertNull($this->inTenant(fn () => Appointment::query()->where('number', $made['number'])->value('client_id')));
    }

    public function test_one_contact_cannot_hold_more_than_a_few_future_bookings_and_a_retry_books_once(): void
    {
        foreach (['10:00', '10:30', '11:00'] as $time) {
            $this->reserve($this->booking($time))->assertCreated();
        }
        $this->reserve($this->booking('11:30'))->assertStatus(422)->assertJsonPath('code', 'too_many_bookings');
        // The same phone written differently is the same contact.
        $this->reserve($this->booking('11:30', ['guest_phone' => '+254 712 345 678']))->assertCreated();

        $first = $this->reserve($this->booking('15:00', ['guest_phone' => '0799000111']), ['Idempotency-Key' => 'retry-1'])->assertCreated()->json('data');
        $again = $this->reserve($this->booking('15:00', ['guest_phone' => '0799000111']), ['Idempotency-Key' => 'retry-1'])->assertCreated()->json('data');
        $this->assertSame($first['number'], $again['number']);
        $this->assertSame(1, $this->inTenant(fn () => Appointment::query()->where('guest_phone', '0799000111')->count()));
    }

    public function test_writing_to_the_page_is_rate_limited_per_address(): void
    {
        config(['salon.booking.writes_per_ip_per_minute' => 2]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->reserve($this->booking('10:00'))->assertCreated();
        $this->reserve($this->booking('10:30', ['guest_phone' => '0722000111']))->assertCreated();
        $this->reserve($this->booking('11:00', ['guest_phone' => '0722000222']))->assertStatus(429);
        // Reading is limited too, on its own count.
        config(['salon.booking.per_ip_per_minute' => 3]);
        \Illuminate\Support\Facades\Cache::flush();
        $codes = [];

        foreach (range(1, 5) as $n) {
            $codes[] = $this->page()->getStatusCode();
        }

        $this->assertSame([200, 200, 200, 429, 429], $codes);
    }

    public function test_a_held_booking_needs_its_deposit_paid_by_the_phone_it_was_made_with_or_it_is_released(): void
    {
        $this->api('PUT', "salon/companies/{$this->acme->id}/settings", ['deposit_percent' => 50, 'deposit_online_required' => true, 'hold_minutes' => 30], $this->owner)->assertOk();
        $made = $this->reserve($this->booking('10:00'))->assertCreated()->json('data');
        $this->assertSame('50000', $made['deposit_required_minor']);
        $this->assertNotNull($made['deposit_hold_until']);

        // Only the number and the phone together reach it.
        $this->postJson('/api/v1/salon-booking/'.self::CODE."/appointments/{$made['number']}/pay", ['phone' => '0700000000'])->assertNotFound();
        $this->getJson('/api/v1/salon-booking/'.self::CODE."/appointments/{$made['number']}?phone=0700000000")->assertNotFound();
        $this->getJson('/api/v1/salon-booking/'.self::CODE."/appointments/{$made['number']}?phone=0712345678")->assertOk()->assertJsonPath('data.deposit_paid_minor', '0');
        // With the company's M-Pesa set up, the right phone starts the push for what is still due; the deposit counts once the provider settles.
        config(['payments.callback_base_url' => 'https://api.example.com', 'payments.mpesa.base_url' => 'https://sandbox.safaricom.co.ke', 'payments.drivers' => []]);
        \Illuminate\Support\Facades\Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'tok', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => \Illuminate\Support\Facades\Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_online1', 'ResponseCode' => '0', 'ResponseDescription' => 'Success', 'CustomerMessage' => 'Success']),
        ]);
        $this->inTenant(fn () => $this->methods['mpesa']->fill(['settings' => ['shortcode' => '174379'], 'secrets' => ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'passkey' => 'pk'], 'active' => true])->save());
        $this->postJson('/api/v1/salon-booking/'.self::CODE."/appointments/{$made['number']}/pay", ['phone' => '0712345678'])->assertOk()->assertJsonPath('data.deposit_paid_minor', '0');
        $pending = $this->inTenant(fn () => \Modules\Salon\Models\Payment::query()->sole());
        $this->assertSame(['deposit', 'pending', '50000', null], [$pending->kind, $pending->status, (string) $pending->amount_minor, $pending->created_by]);

        // Unpaid after the hold: released, no fee, and the time is free again.
        $this->clockAt('08:45');
        Artisan::call('salon:remind');
        $row = $this->inTenant(fn () => Appointment::query()->sole());
        $this->assertSame(['cancelled', 'none'], [$row->status, $row->fee_status]);
        $this->reserve($this->booking('10:00', ['guest_phone' => '0722000333']))->assertCreated();
    }

    public function test_a_signed_in_storefront_customer_books_as_that_customer(): void
    {
        $token = 'ecs_'.str_repeat('a', 48);
        $this->inTenant(function () use ($token) {
            app(ModuleRegistry::class)->activate('ecommerce');
            $store = Store::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'location_id' => $this->locationA->id, 'handle' => 'shop', 'name' => 'Shop', 'status' => 'live', 'currency' => 'KES', 'price_list_id' => $this->retail->id]);
            $customer = Customer::create(['company_id' => $this->acme->id, 'party_id' => $this->client->id, 'name' => 'Amina Client']);
            CustomerSession::create(['customer_id' => $customer->id, 'store_id' => $store->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        });

        $made = $this->reserve(['guest_name' => 'ignored', ...$this->booking('10:00', ['guest_phone' => null])], ['Authorization' => 'Bearer '.$token])->assertCreated()->json('data');
        $row = $this->inTenant(fn () => Appointment::query()->where('number', $made['number'])->sole());
        $this->assertSame([$this->client->id, null, null], [$row->client_id, $row->guest_name, $row->guest_phone]);
        // A token that is not a live customer session is refused, and so is a back-office token.
        $this->reserve($this->booking('11:00'), ['Authorization' => 'Bearer ecs_'.str_repeat('b', 48)])->assertStatus(401)->assertJsonPath('code', 'session_invalid');
        $this->reserve($this->booking('11:00'), $this->headersFor($this->owner))->assertStatus(401);
    }

    public function test_reminders_go_out_once_as_transactional_messages_before_the_visit(): void
    {
        $this->reserve($this->booking('10:00', ['starts_at' => $this->at('10:00', 1), 'guest_email' => 'wendy@example.com']))->assertCreated();
        $count = fn (string $type) => $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', $type)->count());
        $this->assertGreaterThan(0, $count('salon.appointment.confirmed'), 'a confirmation goes when it is booked');

        // 26 hours ahead: not yet. 23 hours ahead: the reminder, once.
        Artisan::call('salon:remind');
        $this->assertSame(0, $count('salon.appointment.reminder'));
        $this->clockAt('11:00');
        Artisan::call('salon:remind');
        $sent = $count('salon.appointment.reminder');
        $this->assertGreaterThan(0, $sent);
        Artisan::call('salon:remind');
        $this->assertSame($sent, $count('salon.appointment.reminder'));
        // Email is on offer to the visitor; with no SMS gateway the text is skipped, never counted as sent.
        $channels = $this->inTenant(fn () => NotificationDelivery::query()->where('event_type', 'salon.appointment.reminder')->pluck('channel')->all());
        $this->assertContains('email', $channels);

        // They are not marketing, so a customer's marketing consent has nothing to do with them.
        $type = app(EventTypes::class)->get('salon.appointment.reminder');
        $this->assertFalse($type->marketing);
        $this->assertTrue($type->system);
    }

    public function test_guests_are_cleared_after_the_retention_and_clients_are_left_alone(): void
    {
        $guest = $this->reserve($this->booking('10:00'))->assertCreated()->json('data');
        $this->book('11:00');
        $this->api('POST', 'salon/queue', ['branch_id' => $this->branchA->id, 'guest_name' => 'Old queue', 'guest_phone' => '0700', 'service_ids' => [$this->haircut->id]], $this->desk)->assertCreated();

        Artisan::call('salon:purge-guests');
        $this->assertNotNull($this->inTenant(fn () => Appointment::query()->where('number', $guest['number'])->value('guest_name')), 'inside the retention nothing is cleared');

        $this->clockAt('09:00', 400);
        Artisan::call('salon:purge-guests');
        $cleared = $this->inTenant(fn () => Appointment::query()->where('number', $guest['number'])->sole());
        $this->assertSame([null, null, null], [$cleared->guest_name, $cleared->guest_phone, $cleared->guest_email]);
        $this->assertNotNull($cleared->anonymised_at);
        $this->assertSame(1, $this->inTenant(fn () => Appointment::query()->whereNull('anonymised_at')->count()), 'the client’s visit stays whole');
        $this->assertSame('-', $this->inTenant(fn () => QueueEntry::query()->sole()->guest_name));
        $this->assertSame(1, AuditEntry::where('action', 'salon.guest.purge')->count());
    }
}
