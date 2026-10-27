<?php

namespace Modules\Salon\Tests;

use Modules\Salon\Models\Package;
use Modules\Salon\Models\Payment;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-27: utilisation, revenue per service, no-shows, commissions and package liability.
class ReportsTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function report(string $name, $user = null, array $query = []): \Illuminate\Testing\TestResponse
    {
        return $this->api('GET', "salon/reports/{$name}?".http_build_query(['branch_id' => $this->branchA->id, 'from' => $this->today, 'to' => $this->today, ...$query]), [], $user ?? $this->manager);
    }

    /** A day's activity: Ann's haircut and colour paid, Bob's haircut a no-show, a cancellation, a package and a deposit. */
    private function aDay(): void
    {
        $haircut = $this->finished('10:00');
        $this->sell($this->salonSale($haircut['id'], 1, $this->desk));
        $colour = $this->book('11:00', $this->ann, [], $this->colour);

        foreach (['check-in', 'start', 'finish'] as $step) {
            $this->api('POST', "salon/appointments/{$colour['id']}/{$step}", [], $this->annUser)->assertOk();
        }

        $this->sell($this->salonSale($colour['id'], 2, $this->desk));
        $absent = $this->book('11:30', $this->bob);
        $cancelled = $this->book('15:00', $this->bob);
        $this->api('POST', "salon/appointments/{$cancelled['id']}/cancel", ['reason' => 'Ill'], $this->desk)->assertOk();
        $this->inTenant(function () use ($absent) {
            Payment::create(['appointment_id' => $absent['id'], 'kind' => 'deposit', 'status' => 'settled', 'method_type' => 'cash', 'currency' => 'KES', 'amount_minor' => 50000]);
            Package::create(['company_id' => $this->acme->id, 'type_id' => $this->fiveCuts->id, 'customer_id' => $this->client->id, 'name' => 'Five haircuts', 'sale_id' => $this->id(), 'sale_line_id' => $this->id(),
                'sessions_total' => 5, 'sessions_used' => 1, 'price_minor' => 450000, 'remaining_minor' => 360000, 'currency' => 'KES', 'service_ids' => [], 'expires_on' => '2027-03-20']);
        });
        $this->clockAt('12:00');
        $this->api('POST', "salon/appointments/{$absent['id']}/no-show", ['reason' => 'Did not come'], $this->desk)->assertOk();
    }

    public function test_the_numbers_of_a_day(): void
    {
        $this->setUpSalon();
        $this->aDay();

        // Ann: 30 + 90 booked minutes of the 420 she was on duty (eight hours less the hour's break); Bob's held none (a no-show and a cancellation free their time).
        $utilisation = collect($this->report('utilisation')->assertOk()->json('data.staff'))->keyBy('staff_name');
        $this->assertSame([420, 120, 120, 2, 28.6], [$utilisation['Ann']['available_minutes'], $utilisation['Ann']['booked_minutes'], $utilisation['Ann']['served_minutes'], $utilisation['Ann']['appointments'], $utilisation['Ann']['utilisation_percent']]);
        $this->assertSame([0, 0.0], [$utilisation['Bob']['booked_minutes'], (float) $utilisation['Bob']['utilisation_percent']]);

        // Revenue per service: the net of what was paid (tax out), the list price beside it.
        $services = collect($this->report('services')->json('data.currencies.KES'))->keyBy('service_name');
        $this->assertSame(['266667', '300000', 1], [$services['Colour']['revenue_minor'], $services['Colour']['list_minor'], $services['Colour']['done']]);
        $this->assertSame(['88889', 1], [$services['Haircut']['revenue_minor'], $services['Haircut']['done']]);
        $this->assertSame('Colour', $this->report('services')->json('data.currencies.KES.0.service_name'), 'the best seller first');

        // No-shows: two visits kept, one missed (a third of the bookings that came due), one cancelled; the no-show's fee is counted.
        $noShows = $this->report('no-shows')->assertOk()->json('data');
        $this->assertSame([2, 1, 1, 33.3, '30000'], [$noShows['total']['kept'], $noShows['total']['no_shows'], $noShows['total']['cancellations'], $noShows['total']['no_show_rate_percent'], $noShows['total']['fees_due_minor']]);
        // (20,000 for the no-show and 10,000 for the late cancellation.)
        $this->assertSame([['Ann', 2, 0], ['Bob', 0, 1]], collect($noShows['staff'])->map(fn ($s) => [$s['staff_name'], $s['kept'], $s['no_shows']])->all());

        // Commissions: Ann earned 20 % of 88,889 and of 266,667.
        $commissions = $this->report('commissions')->assertOk()->json('data.staff');
        $this->assertSame(['Ann', '71111', '0', '71111'], [$commissions[0]['staff_name'], $commissions[0]['earned_minor'], $commissions[0]['reversed_minor'], $commissions[0]['net_minor']]);

        // Liability: four sessions and KES 3,600.00 owed on the package, the deposit held on the missed booking.
        $liability = $this->report('liability')->assertOk()->json('data.currencies.KES');
        $this->assertSame(['1', '4', '360000', '360000', '50000'], [$liability['packages'], $liability['sessions_left'], $liability['value_minor'], $liability['expiring_value_minor'], $liability['deposits_minor']]);
    }

    public function test_reports_are_for_those_who_may_read_them_at_their_own_places(): void
    {
        $this->setUpSalon();
        $this->report('utilisation', $this->desk)->assertForbidden();
        $this->report('services', $this->annUser)->assertForbidden();
        $this->report('liability', $this->desk)->assertForbidden();
        $this->report('utilisation', $this->manager, ['branch_id' => $this->branchB->id])->assertForbidden();
        // A company asked for is the branches the reader reaches: the manager's one.
        $this->api('GET', 'salon/reports/no-shows?company_id='.$this->acme->id, [], $this->manager)->assertOk();
        $this->api('GET', 'salon/reports/no-shows', [], $this->manager)->assertStatus(422);
        $this->report('no-shows', $this->manager, ['from' => '2027-03-04', 'to' => '2027-03-01'])->assertStatus(422);
        $this->report('no-shows', $this->manager, ['from' => '2020-01-01', 'to' => '2027-03-01'])->assertStatus(422)->assertJsonPath('code', 'period_too_long');
        // Another tenant's branch id is not a branch here.
        $other = $this->otherSalonTenant();
        $this->api('GET', 'salon/reports/utilisation?branch_id='.$other['branch']->id ?? $this->id(), [], $this->manager)->assertStatus(422);
    }
}
