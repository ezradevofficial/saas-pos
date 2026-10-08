<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * Four eyes (owner rule) and reassignment limits (APR-06, APR-07): one
 * person counts once per request, delegations stop with a deactivated
 * user, and reassignment never goes to someone who decided, someone
 * without access where the document belongs, or by the requester.
 */
class FourEyesTest extends TestCase
{
    use BuildsApprovals, RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->setUpApprovals();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_an_approver_cannot_vote_again_as_someones_delegate(): void
    {
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $approval = $this->submit($this->approvalGraph(['mode' => 'all']));
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->managerA->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $this->headersFor($second))->assertCreated();

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))
            ->assertForbidden()->assertJsonPath('code', 'already_decided');
        $this->assertContains($second->id, $this->pendingApprovers($approval));
    }

    public function test_one_person_approves_at_most_one_step_of_a_chain(): void
    {
        $approval = $this->submit($this->approvalGraph(['approver' => null, 'chain' => [
            ['type' => 'branch_manager'],
            ['type' => 'user', 'user_id' => $this->managerA->id],
        ]]));

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.step.index', 2);
        // Step 2 names Manager A again: the next level's manager takes it instead.
        $this->assertSame([$this->owner->id], $this->pendingApprovers($approval));
        $this->assertSame('fallback', $this->inTenant(fn () => $approval->assignments()->where('step', 1)->value('source')));
    }

    public function test_a_delegation_stops_when_the_delegator_or_delegate_is_deactivated(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $this->headersFor($this->managerA))->assertCreated();
        $this->getJson($this->approvalUrl($approval), $this->headersFor($this->accountant))->assertOk()->assertJsonPath('data.can.approve', true);

        $this->postJson("/api/v1/users/{$this->managerA->id}/deactivate", [], $this->headersFor())->assertOk();

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();
        $this->assertSame('ended', $this->inTenant(fn () => $this->getJson('/api/v1/me/delegations', $this->headersFor($this->accountant))->json('data.0.status')));
    }

    public function test_reassignment_refuses_voters_people_out_of_scope_and_the_requester(): void
    {
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $approval = $this->submit($this->approvalGraph(['mode' => 'all']));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk();

        // Manager A decided already: they cannot take Mo's vote too.
        $this->postJson($this->approvalUrl($approval, '/reassign'), ['from_user_id' => $second->id, 'to_user_id' => $this->managerA->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'already_decided');
        // Manager B has no access at branch A.
        $this->postJson($this->approvalUrl($approval, '/reassign'), ['from_user_id' => $second->id, 'to_user_id' => $this->managerB->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'cannot_see_document');
        $this->postJson($this->approvalUrl($approval, '/reassign'), ['from_user_id' => $second->id, 'to_user_id' => $this->accountant->id], $this->headersFor())->assertOk();

        // A requester holding reassign at the place cannot move their own request.
        $own = $this->submit($this->approvalGraph(), by: $this->managerA, publish: false);
        $this->postJson($this->approvalUrl($own, '/reassign'), ['from_user_id' => $this->owner->id, 'to_user_id' => $this->accountant->id], $this->headersFor($this->managerA))
            ->assertForbidden()->assertJsonPath('code', 'self_approval');
    }
}
