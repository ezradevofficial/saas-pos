<?php

namespace Modules\CRM\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-08 (RBAC-04, RBAC-07): a rep sees only their own records, a team
// leader with crm.scope.team their team's, a branch manager (crm.scope.branch)
// the branch's; nobody sees another branch's or another tenant's. Every
// endpoint answers 404 out of scope and 403 without the permission.
class ScopeTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    public function test_a_rep_sees_only_their_own_records_across_leads_opportunities_quotes_and_activities(): void
    {
        $mine = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Mine', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        $theirs = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Theirs', 'channel' => 'manual'], $this->repA2)->assertCreated()->json('data');
        $this->assertSame($this->repA->id, $mine['owner']['id']);

        $this->assertSame(['Mine'], array_column($this->api('GET', 'crm/leads', [], $this->repA)->assertOk()->json('data'), 'name'));
        $this->api('GET', "crm/leads/{$theirs['id']}", [], $this->repA)->assertNotFound();
        $this->api('PATCH', "crm/leads/{$theirs['id']}", ['status' => 'contacted'], $this->repA)->assertNotFound();
        $this->api('POST', "crm/leads/{$theirs['id']}/disqualify", ['reason' => 'x'], $this->repA)->assertNotFound();

        $opportunity = $this->api('POST', 'crm/opportunities', ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Deal', 'value_minor' => '100000', 'currency' => 'KES'], $this->repA2)
            ->assertCreated()->json('data');
        $this->api('GET', "crm/opportunities/{$opportunity['id']}", [], $this->repA)->assertNotFound();
        $this->assertSame([], $this->api('GET', 'crm/opportunities', [], $this->repA)->json('data'));

        $quote = $this->draftQuote(as: $this->repA2);
        $this->api('GET', "crm/quotes/{$quote['id']}", [], $this->repA)->assertNotFound();
        $this->api('GET', "crm/quotes/{$quote['id']}/pdf", [], $this->repA)->assertNotFound();
        $this->assertSame([], $this->api('GET', 'crm/quotes', [], $this->repA)->json('data'));

        $activity = $this->api('POST', 'crm/activities', ['branch_id' => $this->branchA->id, 'kind' => 'call', 'subject' => 'Call', 'starts_at' => '2026-10-16T08:00:00Z'], $this->repA2)->assertCreated()->json('data');
        $this->api('PATCH', "crm/activities/{$activity['id']}", ['subject' => 'Hijack'], $this->repA)->assertNotFound();
        $this->assertSame([], $this->api('GET', 'crm/activities?from=2026-10-01&to=2026-10-31', [], $this->repA)->json('data'));

        // A link to a record one cannot see is refused (CRM-26).
        $this->api('POST', 'crm/activities', ['kind' => 'task', 'subject' => 'Peek', 'starts_at' => '2026-10-16T08:00:00Z', 'lead_id' => $theirs['id']], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'link_unknown');
        // The export is the list's rows (EXP-01).
        $csv = $this->get('/api/v1/crm/leads?format=csv', $this->headersFor($this->repA))->assertOk()->streamedContent();
        $this->assertStringContainsString('Mine', $csv);
        $this->assertStringNotContainsString('Theirs', $csv);
    }

    public function test_a_team_leader_sees_the_team_and_a_branch_manager_the_branch(): void
    {
        $this->inTenant(function () {
            $team = Team::query()->create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'Inside sales', 'leader_id' => $this->leader->id]);
            TeamMember::query()->create(['team_id' => $team->id, 'user_id' => $this->repA->id]);
        });
        $teamLead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Team lead', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        $this->assertSame('Inside sales', $teamLead['team']['name']);
        $outside = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Outside', 'channel' => 'manual'], $this->repA2)->assertCreated()->json('data');
        $other = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchB->id, 'name' => 'Branch B', 'channel' => 'manual'], $this->repB)->assertCreated()->json('data');

        $this->assertEqualsCanonicalizing(['Team lead'], array_column($this->api('GET', 'crm/leads', [], $this->leader)->json('data'), 'name'));
        $this->api('GET', "crm/leads/{$teamLead['id']}", [], $this->leader)->assertOk();
        $this->api('GET', "crm/leads/{$outside['id']}", [], $this->leader)->assertNotFound();

        $this->assertEqualsCanonicalizing(['Team lead', 'Outside'], array_column($this->api('GET', 'crm/leads', [], $this->manager)->json('data'), 'name'));
        $this->api('GET', "crm/leads/{$other['id']}", [], $this->manager)->assertNotFound();
        $this->assertEqualsCanonicalizing(['Team lead', 'Outside', 'Branch B'], array_column($this->api('GET', 'crm/leads')->json('data'), 'name'));

        // A rep at branch B creates nothing at branch A (403).
        $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Sneak', 'channel' => 'manual'], $this->repB)->assertForbidden();
    }

    public function test_actions_need_their_permission(): void
    {
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Lead', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        // The rep holds no crm.lead.assign: giving the lead away is forbidden.
        $this->api('POST', "crm/leads/{$lead['id']}/assign", ['owner_id' => $this->repA2->id], $this->repA)->assertForbidden();
        $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Given', 'owner_id' => $this->repA2->id, 'channel' => 'manual'], $this->repA)->assertForbidden();

        $quote = $this->acceptedQuote();
        // No crm.quote.convert, no crm.settings.manage, no crm.target.manage, no crm.quote.approve.
        $this->api('POST', "crm/quotes/{$quote['id']}/convert", ['target' => 'invoice'], $this->repA)->assertForbidden();
        $this->api('PUT', "crm/companies/{$this->acme->id}/settings", ['quote_validity_days' => 10], $this->repA)->assertForbidden();
        $this->api('POST', 'crm/pipelines', ['company_id' => $this->acme->id, 'name' => 'Mine'], $this->repA)->assertForbidden();
        $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'user', 'subject_id' => $this->repA->id, 'metric' => 'won_value',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '100'], $this->repA)->assertForbidden();
        $this->api('GET', 'crm/assignment-rules', [], $this->repA)->assertForbidden();

        // A user without any CRM permission reads nothing.
        $cashier = $this->userWith('cashier', Scope::branch($this->branchA->id));
        foreach (['crm/leads', 'crm/opportunities', 'crm/quotes', 'crm/activities', 'crm/targets', 'crm/pipelines', "crm/customers/{$this->customer->id}/overview"] as $uri) {
            $this->assertContains($this->api('GET', $uri, [], $cashier)->getStatusCode(), [403, 404], $uri);
        }
    }

    public function test_another_tenant_sees_and_changes_nothing(): void
    {
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Ours', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        $quote = $this->draftQuote();
        $other = $this->otherTenant();
        $this->asTenant($other['user']->tenant_id, fn () => app(ModuleRegistry::class)->activate('crm'));
        $theirs = $this->headersFor($other['user']);

        foreach (["crm/leads/{$lead['id']}", "crm/quotes/{$quote['id']}", "crm/customers/{$this->customer->id}/overview", "crm/companies/{$this->acme->id}/settings"] as $uri) {
            $this->getJson("/api/v1/{$uri}", $theirs)->assertNotFound();
        }

        $this->patchJson("/api/v1/crm/leads/{$lead['id']}", ['name' => 'Hijacked'], $theirs)->assertNotFound();
        $this->postJson('/api/v1/crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Into A', 'channel' => 'manual'], $theirs)->assertUnprocessable();
        $this->assertSame([], $this->getJson('/api/v1/crm/leads?status=all', $theirs)->assertOk()->json('data'));
        $this->assertSame('Ours', $this->api('GET', "crm/leads/{$lead['id']}", [], $this->repA)->json('data.name'));
    }
}
