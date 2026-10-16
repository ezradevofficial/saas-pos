<?php

namespace Modules\CRM\Tests;

use App\Core\Audit\AuditEntry;
use App\Core\MasterData\Parties\Party;
use App\Core\Rbac\ModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Modules\CRM\Events\LeadConverted;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadForm;
use Modules\CRM\Models\Option;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-05 to CRM-13: leads typed in, through the API and a public web
// form, dealt by rules and round-robin, converted once to a customer and
// an opportunity.
class LeadsTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    public function test_a_typed_lead_belongs_to_its_creator_and_moves_through_open_statuses(): void
    {
        $lead = $this->api('POST', 'crm/leads', [
            'branch_id' => $this->branchA->id, 'name' => 'Amina Otieno', 'organisation' => 'Duka Moja', 'email' => 'Amina@Example.com',
            'estimated_value_minor' => '1500000', 'channel' => 'manual',
        ], $this->repA)->assertCreated()->json('data');

        $this->assertSame([$this->repA->id, 'new', 'manual', 'amina@example.com', ['amount_minor' => '1500000', 'currency' => 'KES']],
            [$lead['owner']['id'], $lead['status'], $lead['channel'], $lead['email'], $lead['estimated_value']]);
        $this->api('PATCH', "crm/leads/{$lead['id']}", ['status' => 'qualified'], $this->repA)->assertOk()->assertJsonPath('data.status', 'qualified');
        $this->api('PATCH', "crm/leads/{$lead['id']}", ['status' => 'converted'], $this->repA)->assertUnprocessable();
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'crm.lead.create')->where('auditable_id', $lead['id'])->exists()));
    }

    public function test_leads_without_an_owner_are_dealt_by_rules_then_round_robin_in_turn(): void
    {
        $sources = $this->inTenant(fn () => Option::query()->where('company_id', $this->acme->id)->exists());
        $this->assertFalse($sources);
        $this->api('GET', "crm/companies/{$this->acme->id}/settings")->assertOk();
        $website = $this->inTenant(fn () => Option::query()->where('kind', 'source')->where('name', 'Website')->value('id'));

        $team = $this->api('POST', 'crm/teams', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Web team',
            'members' => [['user_id' => $this->repA->id], ['user_id' => $this->repA2->id], ['user_id' => $this->repB->id]]])->assertCreated()->json('data');
        $this->api('POST', 'crm/assignment-rules', ['company_id' => $this->acme->id, 'name' => 'Web', 'source_ids' => [$website], 'assignee_type' => 'team', 'team_id' => $team['id']])->assertCreated();
        $this->api('POST', 'crm/assignment-rules', ['company_id' => $this->acme->id, 'name' => 'Phone in', 'channels' => ['api'], 'assignee_type' => 'user', 'user_id' => $this->repA2->id])->assertCreated();

        // Three web leads through the API: dealt in turn among the members who may view leads at branch A (rep B may not).
        $owners = [];

        foreach (range(1, 3) as $n) {
            $owners[] = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => "Web {$n}", 'source_id' => $website])->assertCreated()->json('data.owner.id');
        }

        $this->assertCount(2, array_unique($owners));
        $this->assertNotContains($this->repB->id, $owners);
        $this->assertSame($owners[0], $owners[2]);
        $this->assertNotSame($owners[0], $owners[1]);

        // No source: the second rule (channel api) gives it to rep A two.
        $this->assertSame($this->repA2->id, $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Called'])->json('data.owner.id'));
        // Typed by a person: theirs, rules aside.
        $this->assertSame($this->owner->id, $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Mine', 'source_id' => $website, 'channel' => 'manual'])->json('data.owner.id'));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'crm.lead.assign')->exists()));
    }

    public function test_a_public_form_captures_a_lead_answers_nothing_else_and_its_old_link_stops_after_rotation(): void
    {
        $team = $this->api('POST', 'crm/teams', ['company_id' => $this->acme->id, 'name' => 'Desk', 'members' => [['user_id' => $this->repA->id]]])->assertCreated()->json('data');
        $form = $this->api('POST', 'crm/lead-forms', ['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Contact us', 'team_id' => $team['id'],
            'fields' => ['shown' => ['email', 'phone'], 'required' => ['email']]])->assertCreated()->json('data');
        $this->assertStringContainsString('/api/v1/crm/forms/', $form['url']);
        $path = parse_url($form['url'], PHP_URL_PATH);

        $this->postJson($path, ['name' => 'Baraka', 'phone' => '+254700000001'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson($path, ['name' => 'Baraka', 'email' => 'baraka@example.com', 'organisation' => 'Hidden field'])->assertStatus(202)->assertExactJson(['data' => ['received' => true]]);
        // A filled honeypot is answered the same and stores nothing.
        $this->postJson($path, ['name' => 'Bot', 'email' => 'bot@example.com', 'website' => 'http://spam.example.com'])->assertStatus(202);

        $leads = $this->inTenant(fn () => Lead::query()->get());
        $this->assertCount(1, $leads);
        $this->assertSame(['form', $this->repA->id, null, 1], [$leads[0]->channel, $leads[0]->owner_id, $leads[0]->organisation, $this->inTenant(fn () => LeadForm::query()->sole()->submissions)]);

        $rotated = $this->api('POST', "crm/lead-forms/{$form['id']}/rotate")->assertOk()->json('data');
        $this->postJson($path, ['name' => 'Late', 'email' => 'late@example.com'])->assertNotFound();
        $this->postJson(parse_url($rotated['url'], PHP_URL_PATH), ['name' => 'On time', 'email' => 'ok@example.com'])->assertStatus(202);
        $this->postJson('/api/v1/crm/forms/'.str_repeat('a', 40), ['name' => 'Guess'])->assertNotFound();

        // RBAC-08: a tenant without the module takes nothing.
        $this->inTenant(fn () => app(ModuleRegistry::class)->deactivate('crm'));
        $this->postJson(parse_url($rotated['url'], PHP_URL_PATH), ['name' => 'Off', 'email' => 'off@example.com'])->assertNotFound();
    }

    public function test_conversion_makes_a_customer_and_an_opportunity_once(): void
    {
        Event::fake([LeadConverted::class]);
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Amina', 'organisation' => 'Kilimo Ltd', 'phone' => '+254711000222',
            'email' => 'amina@kilimo.example.com', 'estimated_value_minor' => '2500000', 'channel' => 'manual'], $this->repA)->json('data');

        $converted = $this->api('POST', "crm/leads/{$lead['id']}/convert", ['opportunity' => ['expected_close_on' => '2026-11-30']], $this->repA)->assertOk()->json('data');

        $this->assertSame(['converted', 'Kilimo Ltd', 'Kilimo Ltd'], [$converted['status'], $converted['party']['name'], $converted['opportunity']['name']]);
        $party = $this->inTenant(fn () => Party::query()->findOrFail($converted['party']['id']));
        $this->assertSame([['customer'], 'organisation', ['+254711000222']], [$party->roles, $party->kind, $party->phoneNumbers()]);
        $opportunity = $this->api('GET', "crm/opportunities/{$converted['opportunity']['id']}", [], $this->repA)->assertOk()->json('data');
        $this->assertSame([['amount_minor' => '2500000', 'currency' => 'KES'], $this->repA->id, 'Qualification', 10, $lead['id']],
            [$opportunity['value'], $opportunity['owner']['id'], $opportunity['stage']['name'], $opportunity['probability'], $opportunity['lead']['id']]);
        Event::assertDispatched(LeadConverted::class);

        $this->api('POST', "crm/leads/{$lead['id']}/convert", [], $this->repA)->assertStatus(409)->assertJsonPath('code', 'lead_closed');
    }

    public function test_conversion_to_an_existing_customer_without_an_opportunity(): void
    {
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Repeat', 'channel' => 'manual'], $this->repA)->json('data');
        $supplier = $this->inTenant(fn () => Party::create(['kind' => 'organisation', 'name' => 'Supplier', 'roles' => ['supplier'], 'company_id' => $this->acme->id]));
        $this->api('POST', "crm/leads/{$lead['id']}/convert", ['party_id' => $supplier->id], $this->repA)->assertUnprocessable()->assertJsonPath('code', 'party_not_customer');

        $converted = $this->api('POST', "crm/leads/{$lead['id']}/convert", ['party_id' => $this->customer->id], $this->repA)->assertOk()->json('data');
        $this->assertSame([$this->customer->id, null], [$converted['party']['id'], $converted['opportunity']]);

        // Making a new customer needs core.party.create (403).
        $this->inTenant(fn () => $this->repRole->revokePermissionTo('core.party.create'));
        $other = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'New one', 'channel' => 'manual'], $this->repA)->json('data');
        $this->api('POST', "crm/leads/{$other['id']}/convert", [], $this->repA)->assertForbidden();
    }

    public function test_leads_import_through_the_import_framework(): void
    {
        $this->api('GET', "crm/companies/{$this->acme->id}/settings")->assertOk();
        $this->inTenant(fn () => $this->repRole->givePermissionTo('core.import.run'));
        $csv = tempnam(sys_get_temp_dir(), 'crm-leads-');
        file_put_contents($csv, "Company,Branch,Name,Email,Source,Estimated value\nAcme,Branch A,Imported One,one@example.com,Website,1500.50\nAcme,Branch A,Bad,bad@example.com,Nowhere,\n");
        $import = $this->post('/api/v1/imports', ['importer' => 'crm_leads', 'file' => new UploadedFile($csv, 'leads.csv', 'text/csv', null, true)], [...$this->headersFor($this->repA), 'Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/imports/{$import}", ['mode' => 'create'], $this->headersFor($this->repA))->assertOk();
        $this->postJson("/api/v1/imports/{$import}/run", [], $this->headersFor($this->repA))->assertStatus(202);

        $lead = $this->inTenant(fn () => Lead::query()->sole());
        $this->assertSame(['Imported One', 'import', 150050, 'KES'], [$lead->name, $lead->channel, $lead->estimated_value_minor, $lead->currency]);
    }
}
