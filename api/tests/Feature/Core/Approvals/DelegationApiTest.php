<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\Delegations;
use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Models\InAppNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

/**
 * APR-06, APR-07: delegation. Users delegate their own approvals for a
 * date range; the delegate sees "Delegated from", decides on their behalf
 * (logged and audited as such), never on their own request, only on the
 * delegator's items and only while the delegation covers the day.
 */
class DelegationApiTest extends TestCase
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

    private function delegate(array $body = []): array
    {
        return $this->postJson('/api/v1/me/delegations', [
            'to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09', ...$body,
        ], $this->headersFor($this->managerA))->assertCreated()->json('data');
    }

    public function test_a_delegate_decides_on_behalf_and_the_log_says_so(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $delegation = $this->delegate();
        $this->assertSame(['given', 'active'], [$delegation['direction'], $delegation['status']]);
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->accountant->id)->where('event_type', 'core.approval.delegated')->exists()));

        $inbox = $this->getJson('/api/v1/approvals', $this->headersFor($this->accountant))->assertOk()->json('data');
        $this->assertSame([$approval->id], array_column($inbox, 'id'));
        $this->assertSame(['id' => $this->managerA->id, 'name' => 'Mary Manager A'], $inbox[0]['my_assignment']['delegated_from']);

        $detail = $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertOk()->json('data');
        $this->assertSame('approved', $detail['status']);
        $row = collect($detail['approvers'])->firstWhere('status', 'approved');
        $this->assertSame([$this->managerA->id, $this->accountant->id, $this->managerA->id], [$row['user']['id'], $row['decided_by']['id'], $row['on_behalf_of']['id']]);

        $audit = $this->inTenant(fn () => AuditEntry::query()->where('action', 'core.approval.approve')->sole());
        $this->assertSame([$this->accountant->id, $this->managerA->id], [$audit->user_id, $audit->on_behalf_of_user_id]);
        $this->assertSame([$delegation['id']], array_column($this->getJson('/api/v1/me/delegations', $this->headersFor($this->accountant))->json('data'), 'id'));
    }

    public function test_a_delegate_cannot_approve_their_own_request(): void
    {
        $this->delegate();
        $approval = $this->submit($this->approvalGraph(), by: $this->accountant);

        $this->getJson($this->approvalUrl($approval), $this->headersFor($this->accountant))->assertOk()->assertJsonPath('data.can.approve', false);
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertForbidden()->assertJsonPath('code', 'self_approval');
        $this->assertSame([$this->managerA->id], $this->pendingApprovers($approval));
    }

    public function test_delegation_covers_only_its_dates_types_and_nodes_allowing_it_and_ends_when_revoked(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $this->delegate(['starts_on' => '2026-10-10', 'ends_on' => '2026-10-12']);
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();

        $this->delegate(['document_types' => ['core.test_order']]);
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();

        $current = $this->delegate();
        $this->postJson("/api/v1/me/delegations/{$current['id']}/revoke", [], $this->headersFor($this->accountant))->assertNotFound();
        $this->postJson("/api/v1/me/delegations/{$current['id']}/revoke", [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();

        // A node that refuses delegation.
        $this->delegate();
        $noDelegation = $this->submit($this->approvalGraph(['allow_delegation' => false]));
        $this->postJson($this->approvalUrl($noDelegation, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertOk();
    }

    public function test_delegations_are_validated(): void
    {
        $headers = $this->headersFor($this->managerA);
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->managerA->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('to_user_id');
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-08', 'ends_on' => '2026-10-07'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08', 'document_types' => ['nope.nope']], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('document_types.0');

        $other = $this->otherTenant();
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $other['user']->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('to_user_id');

        // L1: only someone the delegate picker offers: Ben works at branch B only, not at Mary's places.
        $this->assertNotContains($this->managerB->id, array_column($this->inTenant(fn () => app(Delegations::class)->candidates($this->managerA)), 'id'));
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->managerB->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['to_user_id' => 'Choose someone who works at one of your places.']);
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-08'], $headers)->assertCreated();
    }

    public function test_an_approver_delegating_is_not_a_reason_to_reach_items_of_other_approvers(): void
    {
        // Manager B delegates to the accountant; branch A items are not Manager B's: nothing reachable.
        $approval = $this->submit($this->approvalGraph());
        $this->postJson('/api/v1/me/delegations', ['to_user_id' => $this->accountant->id, 'starts_on' => '2026-10-07', 'ends_on' => '2026-10-09'], $this->headersFor($this->managerB))->assertCreated();

        $this->assertSame([], $this->getJson('/api/v1/approvals', $this->headersFor($this->accountant))->json('data'));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();
        $this->assertSame(0, $this->inTenant(fn () => ApprovalAssignment::query()->where('user_id', $this->accountant->id)->count()));
    }
}
