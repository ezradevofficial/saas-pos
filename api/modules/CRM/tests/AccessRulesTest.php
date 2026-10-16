<?php

namespace Modules\CRM\Tests;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Rbac\Scope;
use Modules\Accounting\Chart\Charts;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Team;
use Modules\CRM\Models\TeamMember;
use Modules\CRM\Tests\Concerns\BuildsCrm;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

// CRM-10, CRM-12, CRM-22, CRM-29, CRM-30 (RBAC-04): who may reassign an
// opportunity (crm.opportunity.assign) and the team that follows the new
// owner; the place a target is moved to needs crm.target.manage; target
// actuals from POS or Accounting only for readers of those records; emails
// only to the lead's or customer's addresses; a quote's send email needs
// crm.quote.send alone; assignees of an unreachable branch are not listed;
// converting a lead into an opportunity needs crm.opportunity.create.
class AccessRulesTest extends TestCase
{
    use BuildsCrm, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCrm();
    }

    /** @return array{0: string, 1: string} team ids: rep A's and rep A two's */
    private function teams(): array
    {
        return $this->inTenant(function () {
            $first = Team::query()->create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'North', 'leader_id' => $this->leader->id]);
            $second = Team::query()->create(['company_id' => $this->acme->id, 'branch_id' => $this->branchA->id, 'name' => 'South']);
            TeamMember::query()->create(['team_id' => $first->id, 'user_id' => $this->repA->id]);
            TeamMember::query()->create(['team_id' => $second->id, 'user_id' => $this->repA2->id]);

            return [$first->id, $second->id];
        });
    }

    public function test_reassigning_an_opportunity_needs_crm_opportunity_assign_and_moves_it_to_the_new_owners_team(): void
    {
        [$north, $south] = $this->teams();
        $deal = ['branch_id' => $this->branchA->id, 'party_id' => $this->customer->id, 'name' => 'Deal', 'value_minor' => '100000', 'currency' => 'KES'];
        $opportunity = $this->api('POST', 'crm/opportunities', $deal, $this->repA)->assertCreated()->json('data');
        $this->assertSame($north, $opportunity['team']['id']);

        // Neither the rep nor the team leader (crm.lead.assign only) may give it away, on create or edit.
        $this->api('PATCH', "crm/opportunities/{$opportunity['id']}", ['owner_id' => $this->repA2->id], $this->repA)->assertForbidden();
        $this->api('PATCH', "crm/opportunities/{$opportunity['id']}", ['owner_id' => $this->repA2->id], $this->leader)->assertForbidden();
        $this->api('POST', 'crm/opportunities', [...$deal, 'owner_id' => $this->repA2->id], $this->repA)->assertForbidden();

        // The branch manager may (crm.opportunity.assign); the team follows the new owner.
        $moved = $this->api('PATCH', "crm/opportunities/{$opportunity['id']}", ['owner_id' => $this->repA2->id], $this->manager)->assertOk()->json('data');
        $this->assertSame([$this->repA2->id, $south], [$moved['owner']['id'], $moved['team']['id']]);
        $this->assertSame($south, $this->api('POST', 'crm/opportunities', [...$deal, 'owner_id' => $this->repA2->id], $this->manager)->assertCreated()->json('data.team.id'));
    }

    public function test_reassigning_an_activity_moves_it_to_the_new_owners_team(): void
    {
        [$north, $south] = $this->teams();
        $activity = $this->api('POST', 'crm/activities', ['branch_id' => $this->branchA->id, 'kind' => 'call', 'subject' => 'Call', 'starts_at' => '2026-10-16T08:00:00Z'], $this->repA)
            ->assertCreated()->json('data');
        $team = fn () => $this->inTenant(fn () => Activity::query()->findOrFail($activity['id'])->team_id);
        $this->assertSame($north, $team());

        $moved = $this->api('PATCH', "crm/activities/{$activity['id']}", ['owner_id' => $this->repA2->id], $this->manager)->assertOk()->json('data');
        $this->assertSame([$this->repA2->id, $south], [$moved['owner']['id'], $team()]);
    }

    public function test_moving_a_target_needs_crm_target_manage_where_it_ends_up(): void
    {
        $target = $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'branch', 'subject_id' => $this->branchA->id, 'metric' => 'won_value',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '100000'], $this->manager)->assertCreated()->json('data');

        // The branch manager of A manages nothing at B.
        $this->api('PATCH', "crm/targets/{$target['id']}", ['subject_id' => $this->branchB->id], $this->manager)->assertForbidden();
        $this->assertSame($this->branchA->id, $this->api('GET', 'crm/targets')->json('data.0.subject.id'));
        $this->api('PATCH', "crm/targets/{$target['id']}", ['amount_minor' => '200000'], $this->manager)->assertOk();
    }

    public function test_pos_and_invoice_actuals_need_the_right_to_read_those_records(): void
    {
        $viewer = $this->inTenant(function () {
            $user = $this->colleague($this->owner, ['name' => 'Target viewer']);
            $this->assign($user, $this->role('Target viewer', ['crm.target.view', 'crm.scope.branch']), Scope::branch($this->branchA->id));

            return $user;
        });

        foreach (['pos_sales', 'invoiced'] as $metric) {
            $this->api('POST', 'crm/targets', ['company_id' => $this->acme->id, 'subject_type' => 'branch', 'subject_id' => $this->branchA->id, 'metric' => $metric,
                'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'amount_minor' => '100000'])->assertCreated();
        }

        $rows = $this->api('GET', 'crm/targets', [], $viewer)->assertOk()->json('data');
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertSame([null, false, 'not_permitted'], [$row['actual_minor'], $row['available'], $row['unavailable']], $row['metric']);
        }
        // With Accounting on, the Owner (accounting.invoice.view) reads the invoiced actual; the viewer still does not.
        $this->inTenant(function () {
            app(ModuleRegistry::class)->activate('accounting');
            app(ModuleRegistry::class)->activate('accounting_pro');
            app(Charts::class)->fromTemplate($this->acme);
        });
        $invoiced = collect($this->api('GET', 'crm/targets')->json('data'))->firstWhere('metric', 'invoiced');
        $this->assertSame(['0', true, null], [$invoiced['actual_minor'], $invoiced['available'], $invoiced['unavailable']]);
        $this->assertSame('not_permitted', collect($this->api('GET', 'crm/targets', [], $viewer)->json('data'))->firstWhere('metric', 'invoiced')['unavailable']);
    }

    public function test_an_email_goes_only_to_an_address_of_the_lead_or_customer(): void
    {
        $email = ['subject' => 'Hello', 'body' => 'Details', 'branch_id' => $this->branchA->id];
        $this->api('POST', 'crm/emails', [...$email, 'to' => 'anyone@example.com'], $this->repA)->assertUnprocessable()->assertJsonValidationErrors(['lead_id', 'party_id']);
        $this->api('POST', 'crm/emails', [...$email, 'to' => 'anyone@example.com', 'party_id' => $this->customer->id], $this->repA)
            ->assertUnprocessable()->assertJsonPath('code', 'email_not_of_recipient')->assertJsonValidationErrors('to');

        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Wanjiru', 'email' => 'wanjiru@example.com', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        $this->api('POST', 'crm/emails', [...$email, 'to' => 'Wanjiru@Example.com', 'lead_id' => $lead['id']], $this->repA)->assertCreated();
        $this->api('POST', 'crm/emails', [...$email, 'to' => 'buyer@duka.example.com', 'party_id' => $this->customer->id], $this->repA)->assertCreated();
        // Another rep's lead is not a way in.
        $this->api('POST', 'crm/emails', [...$email, 'to' => 'wanjiru@example.com', 'lead_id' => $lead['id']], $this->repA2)->assertUnprocessable()->assertJsonPath('code', 'link_unknown');
    }

    public function test_sending_a_quote_needs_crm_quote_send_alone(): void
    {
        $this->inTenant(fn () => $this->repRole->revokePermissionTo('crm.activity.manage'));
        $quote = $this->draftQuote();
        $this->api('POST', "crm/quotes/{$quote['id']}/submit", [], $this->repA)->assertOk();

        $sent = $this->api('POST', "crm/quotes/{$quote['id']}/send", ['email' => 'buyer@duka.example.com'], $this->repA)->assertOk()->json('data');
        $this->assertSame('sent', $sent['status']);
        $this->assertSame(['email', 'done'], [$this->api('GET', 'crm/activities?from=2026-10-01&to=2026-10-31', [], $this->repA)->json('data.0.kind'), $this->api('GET', 'crm/activities?from=2026-10-01&to=2026-10-31', [], $this->repA)->json('data.0.status')]);
    }

    public function test_assignees_of_a_branch_the_caller_does_not_reach_are_not_listed(): void
    {
        $this->api('GET', "crm/assignees?branch={$this->branchB->id}", [], $this->leader)->assertNotFound();
        $names = array_column($this->api('GET', "crm/assignees?branch={$this->branchA->id}", [], $this->leader)->assertOk()->json('data'), 'name');
        $this->assertContains('Rep A', $names);
        $this->assertNotContains('Rep B', $names);
    }

    public function test_converting_a_lead_into_an_opportunity_needs_crm_opportunity_create(): void
    {
        $lead = $this->api('POST', 'crm/leads', ['branch_id' => $this->branchA->id, 'name' => 'Kilimo', 'organisation' => 'Kilimo Ltd', 'channel' => 'manual'], $this->repA)->assertCreated()->json('data');
        $this->inTenant(fn () => $this->repRole->revokePermissionTo('crm.opportunity.create'));

        $this->api('POST', "crm/leads/{$lead['id']}/convert", ['opportunity' => ['expected_close_on' => '2026-11-30']], $this->repA)->assertForbidden();
        $this->assertSame('new', $this->api('GET', "crm/leads/{$lead['id']}", [], $this->repA)->json('data.status'));
        // Without an opportunity the conversion is allowed.
        $this->api('POST', "crm/leads/{$lead['id']}/convert", [], $this->repA)->assertOk()->assertJsonPath('data.opportunity', null);
    }
}
