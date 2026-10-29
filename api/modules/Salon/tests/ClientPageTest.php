<?php

namespace Modules\Salon\Tests;

use App\Core\Rbac\Scope;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-07, SAL-08, SAL-25, RBAC-04, RBAC-05, TEN-01: booking in the back office from the availability list, and the client page's summary.
class ClientPageTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function summary(?string $as = null, ?string $clientId = null)
    {
        return $this->api('GET', 'salon/clients/'.($clientId ?? $this->client->id).'/summary?company_id='.$this->acme->id, [], match ($as) {
            'desk' => $this->desk, 'ann' => $this->annUser, 'manager' => $this->manager, default => $this->owner,
        });
    }

    public function test_the_summary_totals_visits_spend_deposits_and_prepaid_credit_of_one_client(): void
    {
        $this->setUpSalon();
        $this->api('PUT', "salon/companies/{$this->acme->id}/settings", ['deposit_percent' => 50], $this->owner)->assertOk();

        $empty = $this->summary()->assertOk()->json('data');
        $this->assertSame([0, null, null, [], []], [$empty['visits'], $empty['last_visit_at'], $empty['next_appointment'], $empty['spend'], $empty['deposits_held']]);

        // One visit paid (KES 1,000.00), one finished and not yet paid, one upcoming with its deposit held.
        $paid = $this->book('09:00');
        $this->inTenant(fn () => Appointment::query()->whereKey($paid['id'])->update(['status' => 'paid']));
        $this->finished('10:00', $this->bob);
        $upcoming = $this->book('10:00', null, ['starts_at' => $this->at('10:00', 2)]);
        $this->api('POST', "salon/appointments/{$upcoming['id']}/deposit", ['payment_method_id' => $this->methods['cash_kes']->id, 'amount_minor' => '50000'], $this->desk)->assertCreated();

        $data = $this->summary()->assertOk()->json('data');
        $this->assertSame(2, $data['visits']);
        $this->assertSame([['currency' => 'KES', 'amount_minor' => '100000']], $data['spend']);
        $this->assertSame([['currency' => 'KES', 'amount_minor' => '50000']], $data['deposits_held']);
        $this->assertSame([$upcoming['id'], $this->at('10:00', 2)], [$data['next_appointment']['id'], $data['next_appointment']['starts_at']]);
        $this->assertSame([], $data['credit']);
        $this->assertSame(0, $data['no_shows']);

        // Prepaid value comes from active packages (a five-cut package sold to the client).
        $this->sell($this->packageSale(1, $this->client->id));
        $this->assertSame(1, $this->summary()->json('data.credit.0.packages'));
        $this->assertSame('KES', $this->summary()->json('data.credit.0.currency'));
    }

    public function test_the_summary_follows_the_client_permission_and_the_package_permission_and_stays_in_the_tenant(): void
    {
        $this->setUpSalon();

        // The desk reads clients; whether it may read packages decides if the credit is shown at all.
        $this->summary('desk')->assertOk()->assertJsonStructure(['data' => ['visits', 'spend', 'deposits_held', 'credit']]);
        $cashier = $this->userWith('cashier', Scope::location($this->locationA->id));
        $this->api('GET', 'salon/clients/'.$this->client->id.'/summary?company_id='.$this->acme->id, [], $cashier)->assertStatus(403);

        // Another tenant neither reads this client nor finds it.
        $other = $this->otherSalonTenant();
        $this->json('GET', '/api/v1/salon/clients/'.$this->client->id.'/summary', [], $this->headersFor($other['user']))->assertNotFound();
        $this->json('GET', '/api/v1/salon/clients/'.$this->client->id.'/summary?company_id='.$this->acme->id, [], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_a_time_from_the_availability_list_books_once_and_then_leaves_the_list(): void
    {
        $this->setUpSalon();
        $query = 'salon/availability?'.http_build_query(['branch_id' => $this->branchA->id, 'date' => $this->today, 'service_ids' => [$this->haircut->id]]);

        $slot = collect($this->api('GET', $query, [], $this->desk)->assertOk()->json('data.slots'))->firstWhere('starts_at', $this->at('10:00'));
        $this->assertNotNull($slot);
        $body = $this->bookingBody(array_map(fn (array $line) => ['service_id' => $line['service_id'], 'staff_id' => $line['staff_id'], 'resource_id' => $line['resource_id']], $slot['lines']), '10:00');

        $this->api('POST', 'salon/appointments', $body, $this->desk)->assertCreated();
        // The same slot again is a clash, never a second booking.
        $this->api('POST', 'salon/appointments', $body, $this->desk)->assertStatus(409)->assertJsonPath('code', 'slot_taken');
        $this->assertSame(1, $this->inTenant(fn () => Appointment::query()->count()));

        // The booked stylist no longer offers it, and the other one still does.
        $after = collect($this->api('GET', $query, [], $this->desk)->json('data.slots'))->where('starts_at', $this->at('10:00'));
        $this->assertNotContains($slot['lines'][0]['staff_id'], $after->pluck('lines.0.staff_id')->all());
    }
}
