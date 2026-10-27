<?php

namespace Modules\Salon\Tests;

use App\Core\MasterData\Parties\Party;
use Illuminate\Support\Str;
use Modules\POS\Models\Sale;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Setting;
use Modules\Salon\Payments\Credits;
use Modules\Salon\Payments\Deposits;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// The security review of the Salon module: clients across companies, refunds of packages and credit, deposits and the
// bill, back-dated device events, the salon receptionist role and the body field of a client.
class SecurityReviewTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function balance(string $id): int
    {
        return $this->inTenant(fn () => app(Deposits::class)->balance($id));
    }

    public function test_a_client_of_another_company_is_refused_even_when_no_company_is_sent(): void
    {
        $this->setUpSalon();
        $other = $this->inTenant(fn () => $this->company('Other'));
        $theirs = $this->inTenant(fn () => Party::create(['kind' => 'person', 'name' => 'Other Co client', 'roles' => ['customer'], 'company_id' => $other->id]));

        // The desk holds the permission at Acme only: the party's own company is not theirs, sent or not.
        $this->api('GET', "salon/clients/{$theirs->id}", [], $this->desk)->assertForbidden();
        $this->api('GET', "salon/clients/{$theirs->id}/visits", [], $this->desk)->assertForbidden();
        $this->api('PUT', "salon/clients/{$theirs->id}", ['company_id' => $other->id, 'preferences' => 'x'], $this->desk)->assertForbidden();
        // A client of their own company, or a shared one, still reads without a company.
        $this->api('GET', "salon/clients/{$this->client->id}", [], $this->desk)->assertOk()->assertJsonPath('data.name', 'Amina Client');
        // The owner reaches both.
        $this->api('GET', "salon/clients/{$theirs->id}", [], $this->owner)->assertOk();
    }

    public function test_a_shared_client_keeps_a_separate_profile_in_each_company(): void
    {
        $this->setUpSalon();
        $other = $this->inTenant(fn () => $this->company('Other'));
        $shared = $this->inTenant(fn () => Party::create(['kind' => 'person', 'name' => 'Shared client', 'roles' => ['customer']]));

        $this->api('PUT', "salon/clients/{$shared->id}", ['company_id' => $this->acme->id, 'notes' => 'Acme notes'], $this->owner)->assertOk();
        $this->api('PUT', "salon/clients/{$shared->id}", ['company_id' => $other->id, 'notes' => 'Other notes'], $this->owner)->assertOk();

        $this->assertSame(2, $this->inTenant(fn () => Client::query()->where('party_id', $shared->id)->count()));
        $this->api('GET', "salon/clients/{$shared->id}?company_id={$this->acme->id}", [], $this->owner)->assertOk()->assertJsonPath('data.notes', 'Acme notes');
        $this->api('GET', "salon/clients/{$shared->id}?company_id={$other->id}", [], $this->owner)->assertOk()->assertJsonPath('data.notes', 'Other notes');
    }

    public function test_a_refunded_package_line_voids_the_unused_units_and_flags_the_used_ones(): void
    {
        $this->setUpSalon();
        $unused = $this->packageSale(1, $this->client->id);
        $used = $this->packageSale(2, $this->client->id);
        $this->sell($unused, $used);
        $this->inTenant(fn () => Package::query()->where('sale_id', $used['id'])->update(['sessions_used' => 1, 'remaining_minor' => 360000]));

        $this->refundLine($unused, 0, '1', '450000', 1);
        $this->assertSame('void', $this->inTenant(fn () => Package::query()->where('sale_id', $unused['id'])->sole()->status));
        // The same refund seen again changes nothing more.
        $this->assertSame(0, $this->inTenant(fn () => SalonException::query()->count()));

        $this->refundLine($used, 0, '1', '450000', 2);
        $this->assertSame('active', $this->inTenant(fn () => Package::query()->where('sale_id', $used['id'])->sole()->status));
        $this->assertSame(['package_used_on_refund'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
    }

    public function test_deposit_credit_comes_back_at_most_once_however_it_is_refunded_or_voided(): void
    {
        $this->setUpSalon();
        $made = $this->book('10:00');
        $this->api('POST', "salon/appointments/{$made['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();

        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made['id']}/{$step}", [], $this->annUser)->assertOk();
        }

        $credit = $this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $made['id']]);
        $sale = $this->salonSale($made['id'], 1, $this->desk, [], [$credit, $this->cashPayment('50000')]);
        $this->sell($sale);
        $this->assertSame(0, $this->balance($made['id']));

        // Two refunds of the same credit payment, each for all of it, then the void: 50,000 comes back, once.
        $refund = fn (int $amount) => ['sale_id' => $sale['id'], 'currency' => 'KES', 'payments' => [['id' => (string) Str::uuid7(), 'method_type' => 'salon_credit', 'amount_in_sale_minor' => (string) $amount, 'tender' => ['source' => 'deposit']]]];
        $this->inTenant(function () use ($refund) {
            app(Credits::class)->refunded($this->acme->id, $refund(50000));
            app(Credits::class)->refunded($this->acme->id, $refund(50000));
        });
        $this->assertSame(50000, $this->balance($made['id']));
        $this->inTenant(fn () => app(Credits::class)->voided($this->acme->id, ['id' => $sale['id']]));
        $this->assertSame(50000, $this->balance($made['id']));
        $this->assertSame(1, $this->inTenant(fn () => Payment::query()->where('kind', 'restored')->count()));

        // A part refund and then the void give back the rest, no more.
        $made2 = $this->book('11:00');
        $this->api('POST', "salon/appointments/{$made2['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();
        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$made2['id']}/{$step}", [], $this->annUser)->assertOk();
        }
        $sale2 = $this->salonSale($made2['id'], 2, $this->desk, [], [$this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $made2['id']]), $this->cashPayment('50000')]);
        $this->sell($sale2);
        $this->inTenant(function () use ($sale2) {
            app(Credits::class)->refunded($this->acme->id, ['sale_id' => $sale2['id'], 'currency' => 'KES', 'payments' => [['id' => (string) Str::uuid7(), 'method_type' => 'salon_credit', 'amount_in_sale_minor' => '20000', 'tender' => ['source' => 'deposit']]]]);
            app(Credits::class)->voided($this->acme->id, ['id' => $sale2['id']]);
        });
        $this->assertSame(50000, $this->balance($made2['id']));
    }

    public function test_a_deposit_pays_only_the_appointment_the_sale_bills_at_the_sales_branch(): void
    {
        $this->setUpSalon();
        $first = $this->book('10:00');
        $this->api('POST', "salon/appointments/{$first['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();
        $second = $this->finished('11:00');

        // The tender names the first appointment's deposit but the sale bills the second: nothing is applied.
        $credit = $this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $first['id']]);
        $this->sell($this->salonSale($second['id'], 1, $this->desk, [], [$credit, $this->cashPayment('50000')]));
        $this->assertSame(['deposit_mismatch'], $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
        $this->assertSame(50000, $this->balance($first['id']));

        // A deposit held at another branch is not used by a sale of this one.
        $third = $this->book('12:00');
        $this->api('POST', "salon/appointments/{$third['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();
        $this->inTenant(fn () => Appointment::query()->whereKey($third['id'])->update(['branch_id' => $this->branchB->id]));
        $credit = $this->creditPayment('50000', ['source' => 'deposit', 'appointment_id' => $third['id']]);
        $this->sell($this->salonSale($third['id'], 2, $this->desk, [], [$credit, $this->cashPayment('50000')]));
        $this->assertContains('deposit_branch', $this->inTenant(fn () => SalonException::query()->pluck('kind')->all()));
        $this->assertSame(50000, $this->balance($third['id']));
        $this->assertNotNull(Sale::query()->count());
    }

    public function test_a_device_cannot_back_date_a_cancellation_to_avoid_the_late_fee(): void
    {
        $this->setUpSalon();
        $this->inTenant(fn () => Setting::query()->where('company_id', $this->acme->id)->update(['cancel_window_hours' => 24, 'late_cancel_fee_percent' => '10']));
        $made = $this->book('10:00');

        // Two days before the booking existed: the time is held to the booking's creation, so it is still inside the window.
        $event = ['id' => $this->id(), 'appointment_id' => $made['id'], 'type' => 'cancel', 'user_id' => $this->desk->id, 'actor_proof' => $this->actorProof($this->desk->id), 'at' => now()->subDays(3)->toIso8601String(), 'reason' => 'changed mind'];
        $this->postJson('/api/v1/salon/device/sync', ['events' => [$event]], $this->tillHeaders())->assertOk()->assertJsonPath('results.events.0.status', 'stored');
        $row = $this->appointment($made['id']);
        $this->assertSame(['cancelled', 'due'], [$row->status, $row->fee_status]);
        $this->assertGreaterThan(0, (int) $row->fee_minor);
        // And a time in the future is held to now.
        $this->assertTrue($row->cancelled_at->lessThanOrEqualTo(now()));
    }

    public function test_the_hotel_receptionist_has_no_salon_rights_and_the_salon_one_has_them(): void
    {
        $this->setUpSalon();
        $hotel = $this->roles->get('receptionist');
        $salon = $this->roles->get('salon_receptionist');

        $this->inTenant(function () use ($hotel, $salon) {
            $this->assertSame([], array_values(array_filter($hotel->permissions->pluck('name')->all(), fn ($p) => str_starts_with($p, 'salon.'))));
            $this->assertContains('salon.appointment.create', $salon->permissions->pluck('name')->all());
            $this->assertNotContains('hotel.folio.refund', $salon->permissions->pluck('name')->all());
        });
    }

    public function test_the_client_of_a_booking_is_named_in_salon_client_id(): void
    {
        $this->setUpSalon();
        $body = $this->bookingBody([['service_id' => $this->haircut->id, 'staff_id' => $this->ann->id]]);
        $this->assertArrayHasKey('salon_client_id', $body);
        $made = $this->api('POST', 'salon/appointments', $body, $this->desk)->assertCreated()->json('data');
        $this->assertSame($this->client->id, $made['client_id']);
        // The old name is no longer read: a booking with it alone has no client and needs a guest name.
        $old = $body;
        $old['client_id'] = $old['salon_client_id'];
        unset($old['salon_client_id']);
        $old['starts_at'] = $this->at('12:00');
        $this->api('POST', 'salon/appointments', $old, $this->desk)->assertStatus(422);
    }

    public function test_a_list_asked_without_its_branch_is_refused_not_a_server_error(): void
    {
        $this->setUpSalon();
        $this->api('GET', 'salon/availability', [], $this->owner)->assertStatus(422)->assertJsonPath('code', 'branch_unknown');
        $this->api('GET', 'salon/calendar?branch_id=nope', [], $this->owner)->assertStatus(422);
    }
}
