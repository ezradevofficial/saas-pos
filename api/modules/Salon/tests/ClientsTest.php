<?php

namespace Modules\Salon\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\Privacy\SubjectData;
use App\Core\Rbac\Models\FieldRule;
use App\Core\Rbac\Scope;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\Client;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Tests\Concerns\BuildsSalon;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// SAL-25, SAL-26, NFR-08, RBAC-05: client records, their private notes, field rules and the privacy module.
class ClientsTest extends TestCase
{
    use BuildsSalon, RefreshTenantDatabase;

    private function url(?Party $client = null, string $suffix = ''): string
    {
        return 'salon/clients/'.($client ?? $this->client)->id.$suffix.'?company_id='.$this->acme->id;
    }

    public function test_a_client_is_a_customer_with_a_profile_whose_private_notes_need_their_own_permission(): void
    {
        $this->setUpSalon();
        $body = ['company_id' => $this->acme->id, 'preferred_staff_id' => $this->ann->id, 'preferences' => 'Quiet chair, no music'];

        // The desk keeps preferences and the preferred stylist, not allergies or formulas.
        $this->api('PUT', $this->url(), [...$body, 'allergies' => 'Peanut oil'], $this->desk)->assertStatus(403);
        $this->api('PUT', $this->url(), $body, $this->desk)->assertOk()->assertJsonPath('data.preferences', 'Quiet chair, no music')->assertJsonPath('data.has_profile', true);
        $this->api('PUT', $this->url(), [...$body, 'allergies' => 'Peanut oil', 'formulas' => 'Level 6 + 20vol 1:1'], $this->annUser)->assertOk()
            ->assertJsonPath('data.allergies', 'Peanut oil')->assertJsonPath('data.formulas', 'Level 6 + 20vol 1:1')->assertJsonPath('data.has_alerts', true);

        // The desk sees that there are alerts and nothing of them; the stylist sees them.
        $seen = $this->api('GET', $this->url(), [], $this->desk)->assertOk()->json('data');
        $this->assertSame([null, null, true, 'Amina Client'], [$seen['allergies'], $seen['formulas'], $seen['has_alerts'], $seen['name']]);
        $this->assertSame('Peanut oil', $this->api('GET', $this->url(), [], $this->annUser)->json('data.allergies'));
        $this->assertSame(1, $this->inTenant(fn () => Client::query()->count()));
        // They are encrypted at rest.
        $raw = $this->inTenant(fn () => \Illuminate\Support\Facades\DB::table('salon_clients')->first());
        $this->assertStringNotContainsString('Peanut', (string) $raw->allergies);
        $this->assertStringNotContainsString('Level 6', (string) $raw->formulas);

        // The audit log says which fields changed, never what they said.
        $audited = AuditEntry::where('action', 'like', 'salon.client.%')->get();
        $this->assertContains('allergies', collect($audited)->flatMap(fn ($e) => (array) ($e->after['fields'] ?? []))->all());
        $this->assertStringNotContainsString('Peanut', json_encode($audited->map->only(['before', 'after'])->all()));
        $this->assertStringNotContainsString('Quiet chair', json_encode($audited->map->only(['before', 'after'])->all()));

        // A staff member of the company only, an archived or alien preference is refused; a supplier is not a client.
        $this->api('PUT', $this->url(), ['company_id' => $this->acme->id, 'preferred_staff_id' => $this->id()], $this->desk)->assertStatus(422);
        $supplier = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id]));
        $this->api('GET', $this->url($supplier), [], $this->desk)->assertStatus(422)->assertJsonPath('code', 'client_unknown');
        $this->api('PUT', $this->url(), $body, $this->branchManager)->assertOk();
        $this->api('PUT', $this->url(), $body, $this->branchManager === null ? null : $this->owner)->assertOk();
    }

    public function test_a_new_client_is_made_with_a_name_and_contacts_and_found_by_search(): void
    {
        $this->setUpSalon();
        $made = $this->api('POST', 'salon/clients', ['company_id' => $this->acme->id, 'name' => 'Zawadi Mwangi', 'phone' => '+254711222333', 'email' => 'zawadi@example.com'], $this->desk)->assertCreated()->json('data');
        $this->assertSame(['Zawadi Mwangi', '+254711222333', 'zawadi@example.com', true], [$made['name'], $made['phone'], $made['email'], $made['has_profile']]);
        $party = $this->inTenant(fn () => Party::query()->findOrFail($made['party_id']));
        $this->assertContains('customer', $party->roles);
        $this->api('POST', 'salon/clients', ['company_id' => $this->acme->id, 'name' => 'Nope'], $this->annUser === null ? null : $this->bobUser)->assertCreated();

        $found = $this->api('GET', 'salon/clients?company_id='.$this->acme->id.'&search=zawa', [], $this->desk)->assertOk()->json('data');
        $this->assertSame(['Zawadi Mwangi'], array_column($found, 'name'));
        $this->assertSame(['Zawadi Mwangi'], array_column($this->api('GET', 'salon/clients?company_id='.$this->acme->id.'&search=711222', [], $this->desk)->json('data'), 'name'));
        $this->api('POST', 'salon/clients', ['company_id' => $this->acme->id, 'name' => 'X'], $this->owner)->assertCreated();
        $other = $this->otherSalonTenant();
        $this->getJson('/api/v1/salon/clients?company_id='.$this->acme->id.'&search=zawa', $this->headersFor($other['user']))->assertStatus(422);
    }

    public function test_field_rules_hide_contacts_and_notes_even_from_a_role_that_may_read_them(): void
    {
        $this->setUpSalon();
        $this->api('PUT', $this->url(), ['company_id' => $this->acme->id, 'allergies' => 'Latex', 'notes' => 'VIP'], $this->annUser)->assertOk();
        $role = $this->inTenant(function () {
            $role = $this->role('Careful desk', ['core.company.view', 'salon.client.view', 'salon.client.manage', 'salon.client.notes', 'salon.appointment.view']);

            foreach (['contact', 'allergies', 'notes'] as $field) {
                FieldRule::create(['role_id' => $role->id, 'resource' => 'salon_client', 'field' => $field, 'mode' => 'hidden']);
            }

            return $role;
        });
        $user = $this->inTenant(function () use ($role) {
            $u = $this->colleague($this->owner);
            $this->assign($u, $role, Scope::company($this->acme->id));

            return $u;
        });

        $shown = $this->api('GET', $this->url(), [], $user)->assertOk()->json('data');
        foreach (['phone', 'email', 'allergies', 'notes'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $shown);
        }

        $this->assertArrayHasKey('formulas', $shown);
        // A hidden field is not writable either.
        $this->api('PUT', $this->url(), ['company_id' => $this->acme->id, 'allergies' => 'Changed'], $user)->assertForbidden();
        // The appointment shows the client's contact only to those whose rules allow it.
        $made = $this->book('10:00', null, ['guest_phone' => '0700555666']);
        $this->assertNull($this->api('GET', "salon/appointments/{$made['id']}", [], $user)->assertOk()->json('data.guest_phone'));
        $this->assertSame('+254700111222', $this->api('GET', "salon/appointments/{$made['id']}", [], $this->desk)->json('data.guest_phone'));
    }

    public function test_the_visit_formula_is_written_by_a_stylist_and_read_only_by_those_who_may(): void
    {
        $this->setUpSalon();
        $made = $this->finished('10:00');
        $this->api('PUT', "salon/appointments/{$made['id']}/notes", ['service_notes' => 'Level 6 + 20vol, 35 min'], $this->desk)->assertForbidden();
        $this->api('PUT', "salon/appointments/{$made['id']}/notes", ['service_notes' => 'Level 6 + 20vol, 35 min'], $this->bobUser)->assertForbidden();
        $this->api('PUT', "salon/appointments/{$made['id']}/notes", ['service_notes' => 'Level 6 + 20vol, 35 min'], $this->annUser)->assertOk()->assertJsonPath('data.service_notes', 'Level 6 + 20vol, 35 min');
        $this->assertNull($this->api('GET', "salon/appointments/{$made['id']}", [], $this->desk)->json('data.service_notes'));
        $this->assertSame('Level 6 + 20vol, 35 min', $this->api('GET', "salon/appointments/{$made['id']}", [], $this->manager)->json('data.service_notes'));

        $visits = $this->api('GET', $this->url(null, '/visits'), [], $this->manager)->assertOk()->json('data');
        $this->assertSame(['completed', 'Level 6 + 20vol, 35 min', 'Haircut'], [$visits[0]['status'], $visits[0]['service_notes'], $visits[0]['lines'][0]['service_name']]);
        $this->assertNull($this->api('GET', $this->url(null, '/visits'), [], $this->desk)->json('data.0.service_notes'));
        $this->assertSame(1, AuditEntry::where('action', 'salon.appointment.notes')->count());
        $this->assertStringNotContainsString('Level 6', json_encode(AuditEntry::query()->get()->map->only(['before', 'after'])->all()));
        $this->api('GET', $this->url(null, '/visits'), [], $this->desk === null ? null : $this->owner)->assertOk();
    }

    public function test_the_privacy_module_finds_clears_and_keeps_what_it_should(): void
    {
        $this->setUpSalon();
        $this->api('PUT', $this->url(), ['company_id' => $this->acme->id, 'allergies' => 'Latex', 'preferences' => 'Likes tea'], $this->annUser)->assertOk();
        $made = $this->finished('10:00', null, ['notes' => 'Bring a photo']);
        $this->api('PUT', "salon/appointments/{$made['id']}/notes", ['service_notes' => 'Formula X'], $this->annUser)->assertOk();
        $this->inTenant(function () {
            QueueEntry::create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'client_id' => $this->client->id, 'guest_name' => $this->client->name, 'guest_phone' => '0700', 'service_ids' => [], 'joined_at' => now()]);
            Package::create(['company_id' => $this->acme->id, 'type_id' => $this->fiveCuts->id, 'customer_id' => $this->client->id, 'name' => 'Five haircuts', 'sale_id' => $this->id(), 'sale_line_id' => $this->id(),
                'sessions_total' => 5, 'price_minor' => 450000, 'remaining_minor' => 450000, 'currency' => 'KES', 'service_ids' => []]);
        });

        // The access report lists the salon's rows about the person, with the field rules applied; the packages are business records, counted.
        $report = $this->inTenant(fn () => app(SubjectData::class)->report('customer', $this->client->id, $this->owner));
        foreach (['salon_clients', 'salon_appointments', 'salon_queue'] as $table) {
            $this->assertArrayHasKey($table, $report['records'], "{$table} is in the access report");
        }

        $this->assertSame(1, $report['related']['salon_packages']['count']);
        $this->assertSame(1, count($report['records']['salon_clients']));

        // A deletion clears the notes, names and formulas, keeps the visits and the packages (and so the sales and counts).
        $this->inTenant(fn () => app(SubjectData::class)->anonymise('customer', $this->client->id, 'Anonymised ABC'));
        $profile = $this->inTenant(fn () => Client::query()->sole());
        $this->assertSame([null, null], [$profile->allergies, $profile->preferences]);
        $appointment = $this->appointment($made['id']);
        $this->assertSame([null, null, 'completed'], [$appointment->notes, $appointment->service_notes, $appointment->status]);
        $this->assertSame('-', $this->inTenant(fn () => QueueEntry::query()->sole()->guest_name) === 'Anonymised ABC' ? '-' : 'kept');
        $this->assertSame(1, $this->inTenant(fn () => Package::query()->count()));
        $this->assertSame(1, $this->inTenant(fn () => Appointment::query()->count()));
    }
}
