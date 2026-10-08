<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Rbac\Scope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\Support\Workflow\TestRequestType;
use Tests\TestCase;

/**
 * APR-04, APR-06: what the web inbox picks from: reassignment candidates,
 * delegation candidates, the document types that can have approvals, and
 * the company's time zone on each item.
 */
class ApprovalPickersTest extends TestCase
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

    public function test_reassign_candidates_are_people_with_access_minus_requester_approvers_and_voters(): void
    {
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $clerk = $this->person('cashier', Scope::location($this->locationA->id), 'Cleo Clerk A');
        $approval = $this->submit($this->approvalGraph(['mode' => 'all']));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk();

        // The request is at branch A: the location clerk's role does not cover it; Manager B has no access.
        $data = $this->getJson($this->approvalUrl($approval, '/reassign-candidates'), $this->headersFor())->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->accountant->id], array_column($data, 'id'));
        $this->assertSame(['id', 'name'], array_keys($data[0]));
        $this->assertNotContains($second->id, array_column($data, 'id'));
        $this->assertNotContains($clerk->id, array_column($data, 'id'));

        // Only people who may reassign; others who see it get 403, strangers 404.
        $this->getJson($this->approvalUrl($approval, '/reassign-candidates'), $this->headersFor($this->requester))->assertForbidden();
        $this->getJson($this->approvalUrl($approval, '/reassign-candidates'), $this->headersFor($this->managerB))->assertNotFound();
    }

    public function test_delegation_candidates_overlap_the_users_places_and_can_be_searched(): void
    {
        $colleagueA = $this->person('cashier', Scope::location($this->locationA->id), 'Cleo Clerk A');
        $this->person('cashier', Scope::location($this->locationB->id), 'Bea Clerk B');
        $other = $this->otherTenant();

        $data = $this->getJson('/api/v1/me/delegation-candidates', $this->headersFor($this->managerA))->assertOk()->json('data');
        $ids = array_column($data, 'id');
        // Branch A, its outlet, the company above and the tenant-wide owner; never branch B only, themselves or another tenant.
        $this->assertEqualsCanonicalizing([$this->owner->id, $this->requester->id, $this->accountant->id, $colleagueA->id], $ids);
        $this->assertNotContains($other['user']->id, $ids);

        $this->assertSame([$colleagueA->id], array_column($this->getJson('/api/v1/me/delegation-candidates?search=cleo', $this->headersFor($this->managerA))->json('data'), 'id'));
    }

    public function test_document_types_that_can_have_approvals_and_the_company_time_zone(): void
    {
        $this->getJson('/api/v1/approvals/document-types', $this->headersFor($this->requester))->assertOk()
            ->assertExactJson(['data' => [['key' => TestRequestType::KEY, 'label' => __('workflow.list_title')]]]);

        $approval = $this->submit($this->approvalGraph());
        $this->getJson('/api/v1/approvals', $this->headersFor($this->managerA))->assertJsonPath('data.0.company.timezone', 'Africa/Nairobi');
        $this->getJson($this->approvalUrl($approval), $this->headersFor($this->managerA))->assertJsonPath('data.company.timezone', 'Africa/Nairobi');
    }
}
