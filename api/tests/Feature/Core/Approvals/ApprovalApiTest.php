<?php

namespace Tests\Feature\Core\Approvals;

use App\Core\Approvals\ApprovalDecisions;
use App\Core\Approvals\Models\ApprovalAction;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Audit\AuditEntry;
use App\Core\Notifications\Models\InAppNotification;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Models\DocumentWorkflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsApprovals;
use Tests\Concerns\RefreshTenantDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * APR-01, APR-03, APR-04, APR-06, APR-07, APR-09: the approvals API — the
 * inbox, detail, approve/reject/return/comment/request-info, attachments,
 * reassignment, bulk approval, modes and chains, no self-approval, other
 * tenants and people who are not approvers.
 */
class ApprovalApiTest extends TestCase
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

    public function test_the_branch_manager_gets_the_request_in_the_inbox_and_approves_it(): void
    {
        $approval = $this->submit($this->approvalGraph(), ['note' => 'Laptops']);

        $this->assertSame([$this->managerA->id], $this->pendingApprovers($approval));
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->managerA->id)->where('event_type', 'core.approval.requested')->exists()));

        $inbox = $this->getJson('/api/v1/approvals', $this->headersFor($this->managerA))->assertOk();
        $this->assertSame([$approval->id], array_column($inbox->json('data'), 'id'));
        $item = $inbox->json('data.0');
        $this->assertSame(['amount_minor' => '12000000', 'currency' => 'KES'], $item['document']['amount']);
        $this->assertSame('Manager approves', $item['step']['name']);
        $this->assertSame('Rita Requester', $item['requester']['name']);
        $this->assertTrue($item['can']['approve']);
        $this->assertNull($item['my_assignment']['delegated_from']);

        // Manager B and the requester's inbox are empty.
        $this->assertSame([], $this->getJson('/api/v1/approvals', $this->headersFor($this->managerB))->assertOk()->json('data'));

        $done = $this->postJson($this->approvalUrl($approval, '/approve'), ['comment' => 'Fine'], $this->headersFor($this->managerA))->assertOk();
        $done->assertJsonPath('data.status', 'approved');
        $this->assertSame('completed', $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id))->status);
        $this->assertSame('approved', $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id))->outcome);
        $this->assertContains('approved', array_column($done->json('data.history'), 'type'));
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.approval.approve')->where('user_id', $this->managerA->id)->exists()));
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->requester->id)->where('event_type', 'core.approval.decided')->exists()));

        // Decided: it moves to the "decided" list and cannot be decided again.
        $this->assertSame([], $this->getJson('/api/v1/approvals', $this->headersFor($this->managerA))->json('data'));
        $this->assertSame([$approval->id], array_column($this->getJson('/api/v1/approvals?status=decided', $this->headersFor($this->managerA))->json('data'), 'id'));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertUnprocessable()->assertJsonPath('code', 'approval_not_pending');
    }

    public function test_reject_needs_a_reason_and_follows_the_rejected_path(): void
    {
        $approval = $this->submit($this->approvalGraph());

        $this->postJson($this->approvalUrl($approval, '/reject'), [], $this->headersFor($this->managerA))
            ->assertUnprocessable()->assertJsonPath('code', 'reason_required');
        $this->postJson($this->approvalUrl($approval, '/reject'), ['comment' => 'Over budget'], $this->headersFor($this->managerA))
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('rejected', $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id))->outcome);
    }

    public function test_require_reason_needs_a_comment_to_approve_and_turns_bulk_off(): void
    {
        $approval = $this->submit($this->approvalGraph(['require_reason' => true]));

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertUnprocessable();
        $item = $this->getJson($this->approvalUrl($approval), $this->headersFor($this->managerA))->assertOk()->json('data');
        $this->assertFalse($item['can']['bulk_approve']);
        $this->assertFalse($item['settings']['allow_bulk']);
    }

    public function test_people_who_are_not_approvers_cannot_see_or_act_and_other_tenants_get_404(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $stranger = $this->person('cashier', Scope::location($this->locationB->id), 'Sam Stranger');

        $this->getJson($this->approvalUrl($approval), $this->headersFor($stranger))->assertNotFound();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($stranger))->assertNotFound();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerB))->assertNotFound();

        // The requester sees it but may not decide it (APR-07).
        $this->getJson($this->approvalUrl($approval), $this->headersFor($this->requester))->assertOk()->assertJsonPath('data.can.approve', false);
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->requester))->assertForbidden()->assertJsonPath('code', 'not_assignee');

        // An admin overseeing sees it but is not an approver.
        $this->getJson($this->approvalUrl($approval), $this->headersFor())->assertOk();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor())->assertForbidden();

        $other = $this->otherTenant();
        $this->getJson($this->approvalUrl($approval), $this->headersFor($other['user']))->assertNotFound();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($other['user']))->assertNotFound();
    }

    public function test_the_requester_is_never_an_approver_and_the_step_moves_to_the_next_level(): void
    {
        // Manager A submits: they hold the branch manager role, so the company's managers get it (APR-07).
        $companyManager = $this->person('admin', Scope::company($this->acme->id), 'Carl Company Admin');
        $approval = $this->submit($this->approvalGraph(), by: $this->managerA);

        $this->assertSame([$companyManager->id], $this->pendingApprovers($approval));
        $this->assertSame('fallback', $this->inTenant(fn () => $approval->assignments()->value('source')));
        $this->assertSame([], $this->getJson('/api/v1/approvals', $this->headersFor($this->managerA))->json('data'));
    }

    public function test_nobody_eligible_blocks_the_request_with_a_reason_until_an_admin_reassigns_it(): void
    {
        $approval = $this->submit($this->approvalGraph(['approver' => ['type' => 'user', 'user_id' => $this->requester->id]]), by: $this->requester);

        // The tenant Owner is the next-level manager at tenant scope: the step goes there.
        $this->assertSame([$this->owner->id], $this->pendingApprovers($approval));

        $graph = $this->approvalGraph(['approver' => ['type' => 'user', 'user_id' => $this->owner->id]], ['escalation' => ['to' => ['type' => 'role', 'role' => 'template:waiter'], 'after' => ['amount' => 1, 'unit' => 'days']]]);
        $blocked = $this->submit($graph, by: $this->owner);
        $this->assertSame(ApprovalRequest::BLOCKED_NO_APPROVER, $this->fresh($blocked)->blocked_reason);
        $status = $this->getJson($this->workflowUrl($this->inTenant(fn () => DocumentWorkflow::query()->find($blocked->workflow_id))->document_id), $this->headersFor())->assertOk();
        $status->assertJsonPath('data.current.0.holders.blocked', 'no_approver');

        $this->getJson($this->approvalUrl($blocked), $this->headersFor())->assertOk()->assertJsonPath('data.blocked_reason', 'no_approver');
        // The owner requested it, so cannot reassign it; the branch manager can.
        $this->postJson($this->approvalUrl($blocked, '/reassign'), ['from_user_id' => null, 'to_user_id' => $this->accountant->id], $this->headersFor())->assertForbidden();
        $this->postJson($this->approvalUrl($blocked, '/reassign'), ['from_user_id' => null, 'to_user_id' => $this->accountant->id], $this->headersFor($this->managerA))->assertOk();
        $this->assertSame([$this->accountant->id], $this->pendingApprovers($blocked));
        $this->assertNull($this->fresh($blocked)->blocked_reason);
    }

    public function test_all_mode_needs_every_approver_and_one_rejection_rejects(): void
    {
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $approval = $this->submit($this->approvalGraph(['mode' => 'all']));
        $this->assertCount(2, $this->pendingApprovers($approval));

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.status', 'pending');
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($second))->assertOk()->assertJsonPath('data.status', 'approved');

        $other = $this->submit($this->approvalGraph(['mode' => 'all']), publish: false);
        $this->postJson($this->approvalUrl($other, '/reject'), ['comment' => 'No'], $this->headersFor($second))->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertSame([], $this->pendingApprovers($other));
    }

    public function test_majority_mode_decides_on_more_than_half(): void
    {
        $second = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mo Manager A2');
        $third = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Max Manager A3');
        $approval = $this->submit($this->approvalGraph(['mode' => 'majority']));

        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertJsonPath('data.status', 'pending');
        $this->postJson($this->approvalUrl($approval, '/reject'), ['comment' => 'No'], $this->headersFor($second))->assertJsonPath('data.status', 'pending');
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($third))->assertJsonPath('data.status', 'approved');
    }

    public function test_a_sequential_chain_goes_to_each_approver_in_turn(): void
    {
        $approval = $this->submit($this->approvalGraph(['approver' => null, 'chain' => [
            ['type' => 'branch_manager'],
            ['type' => 'role', 'role' => 'template:accountant'],
        ]]));

        $this->assertSame([$this->managerA->id], $this->pendingApprovers($approval));
        // Not an approver of this request yet: not even visible to them.
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertNotFound();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.step.index', 2);
        $this->assertSame([$this->accountant->id], $this->pendingApprovers($approval));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_return_for_changes_sends_the_document_back_and_notifies_the_requester(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $detail = $this->getJson($this->approvalUrl($approval), $this->headersFor($this->managerA))->assertOk();
        $this->assertSame([['node_id' => 'prepare', 'name' => 'Prepare']], $detail->json('data.return_targets'));

        $this->postJson($this->approvalUrl($approval, '/return'), ['node' => 'prepare'], $this->headersFor($this->managerA))->assertUnprocessable();
        $this->postJson($this->approvalUrl($approval, '/return'), ['node' => 'prepare', 'reason' => 'Add the quote'], $this->headersFor($this->managerA))
            ->assertOk()->assertJsonPath('data.status', 'returned');

        $workflow = $this->inTenant(fn () => DocumentWorkflow::query()->find($approval->workflow_id));
        $this->assertSame(['prepare'], $this->at($workflow));
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->requester->id)->where('event_type', 'core.approval.returned')->exists()));

        // Back at the approval: a new request, the old one stays returned.
        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));
        $again = $this->approvalOf($workflow);
        $this->assertNotSame($approval->id, $again->id);
        $this->assertSame('pending', $again->status);
    }

    public function test_comment_and_request_information(): void
    {
        $approval = $this->submit($this->approvalGraph());

        $this->postJson($this->approvalUrl($approval, '/request-info'), ['comment' => 'Which supplier?'], $this->headersFor($this->managerA))->assertOk();
        $this->assertTrue($this->inTenant(fn () => InAppNotification::query()->where('user_id', $this->requester->id)->where('event_type', 'core.approval.info_requested')->exists()));
        $this->assertSame('pending', $this->fresh($approval)->status);

        // The requester answers with a comment; only approvers ask for information.
        $this->postJson($this->approvalUrl($approval, '/comment'), ['comment' => 'Supplier X'], $this->headersFor($this->requester))->assertOk();
        $this->postJson($this->approvalUrl($approval, '/request-info'), ['comment' => 'Hmm'], $this->headersFor($this->requester))->assertForbidden();

        $types = $this->inTenant(fn () => ApprovalAction::query()->where('request_id', $approval->id)->orderBy('occurred_at')->pluck('type')->all());
        $this->assertSame(['requested', 'info_requested', 'commented'], $types);
    }

    public function test_attachments_are_validated_and_served_only_to_people_who_see_the_request(): void
    {
        Storage::fake('media');
        $approval = $this->submit($this->approvalGraph());

        $this->post($this->approvalUrl($approval, '/attachments'), ['file' => UploadedFile::fake()->create('evil.exe', 10, 'application/x-msdownload')], $this->headersFor($this->managerA) + ['Accept' => 'application/json'])
            ->assertUnprocessable();
        $stranger = $this->person('cashier', Scope::location($this->locationB->id), 'Sam Stranger');
        $this->post($this->approvalUrl($approval, '/attachments'), ['file' => UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')], $this->headersFor($stranger) + ['Accept' => 'application/json'])
            ->assertNotFound();

        $created = $this->post($this->approvalUrl($approval, '/attachments'), ['file' => UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')], $this->headersFor($this->requester) + ['Accept' => 'application/json'])
            ->assertCreated();
        $file = $created->json('data.attachments.0');
        $this->assertSame('quote.pdf', $file['name']);

        $this->get($file['url'])->assertOk()->assertHeader('content-type', 'application/pdf');

        // The managers' URL is bound to the manager; tampering breaks the signature.
        $url = $this->getJson($this->approvalUrl($approval), $this->headersFor($this->managerA))->json('data.attachments.0.url');
        $this->get($url)->assertOk();
        $this->get(str_replace($this->managerA->id, $stranger->id, $url))->assertForbidden();
    }

    public function test_reassignment_needs_the_permission_and_never_goes_to_the_requester(): void
    {
        $approval = $this->submit($this->approvalGraph());
        $body = ['from_user_id' => $this->managerA->id, 'to_user_id' => $this->accountant->id];

        // Manager B holds reassign only at branch B: not found for them? They cannot see it.
        $this->postJson($this->approvalUrl($approval, '/reassign'), $body, $this->headersFor($this->managerB))->assertNotFound();
        // Manager A sees it (approver) but holds reassign at branch A: allowed.
        $this->postJson($this->approvalUrl($approval, '/reassign'), [...$body, 'to_user_id' => $this->requester->id], $this->headersFor())
            ->assertUnprocessable()->assertJsonPath('code', 'ineligible_approver');
        // The Branch Manager template holds reassign at its branch.
        $this->postJson($this->approvalUrl($approval, '/reassign'), [...$body, 'reason' => 'On leave'], $this->headersFor($this->managerA))->assertOk();

        $this->assertSame([$this->accountant->id], $this->pendingApprovers($approval));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertForbidden();
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->accountant))->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($this->inTenant(fn () => AuditEntry::query()->where('action', 'core.approval.reassign')->exists()));

        // Someone who can see it but has no reassign permission: 403.
        $other = $this->submit($this->approvalGraph(), publish: false);
        $this->postJson($this->approvalUrl($other, '/reassign'), $body, $this->headersFor($this->requester))->assertForbidden();
    }

    public function test_bulk_approve_approves_each_allowed_item_and_reports_the_others(): void
    {
        $ok = $this->submit($this->approvalGraph());
        $alsoOk = $this->submit($this->approvalGraph(), publish: false);
        $notMine = $this->submit($this->approvalGraph(), ['note' => 'B'], publish: false);
        $this->inTenant(fn () => $notMine->assignments()->update(['user_id' => $this->accountant->id]));

        $response = $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => [$ok->id, $alsoOk->id, $notMine->id, '00000000-0000-7000-8000-000000000000']], $this->headersFor($this->managerA))->assertOk();
        $this->assertEqualsCanonicalizing([$ok->id, $alsoOk->id], $response->json('data.approved'));
        $this->assertSame(['not_assignee', 'not_found'], array_column($response->json('data.failed'), 'code'));

        $reason = $this->submit($this->approvalGraph(['require_reason' => true]));
        $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => [$reason->id]], $this->headersFor($this->managerA))
            ->assertOk()->assertJsonPath('data.failed.0.code', 'bulk_not_allowed');
    }

    public function test_bulk_approve_reports_an_unexpected_failure_and_keeps_the_others(): void
    {
        $bad = $this->submit($this->approvalGraph());
        $good = $this->submit($this->approvalGraph(), publish: false);
        $real = app(ApprovalDecisions::class);
        $this->mock(ApprovalDecisions::class, fn ($mock) => $mock->shouldReceive('decide')->andReturnUsing(
            fn (ApprovalRequest $request, ...$rest) => $request->id === $bad->id ? throw new RuntimeException('boom') : $real->decide($request, ...$rest),
        ));

        $response = $this->postJson('/api/v1/approvals/bulk-approve', ['ids' => [$bad->id, $good->id]], $this->headersFor($this->managerA))->assertOk();
        $this->assertSame([$good->id], $response->json('data.approved'));
        $this->assertSame([['id' => $bad->id, 'code' => 'error', 'message' => __('approvals.errors.bulk_item_failed')]], $response->json('data.failed'));
        $this->assertSame(['pending', 'approved'], [$this->fresh($bad)->status, $this->fresh($good)->status]);
    }

    public function test_in_progress_requests_keep_their_versions_configuration(): void
    {
        $approval = $this->submit($this->approvalGraph());
        // v2 sends approvals to the accountant; the running request stays with the branch manager (APR-09).
        $this->publishFlow($this->approvalGraph(['approver' => ['type' => 'role', 'role' => 'template:accountant']]));

        $this->assertSame([$this->managerA->id], $this->pendingApprovers($approval));
        $this->postJson($this->approvalUrl($approval, '/approve'), [], $this->headersFor($this->managerA))->assertOk()->assertJsonPath('data.version.number', 1);

        $next = $this->submit([], publish: false);
        $this->assertSame([$this->accountant->id], $this->pendingApprovers($next));
    }

    public function test_the_inbox_filters_searches_sorts_and_oversees(): void
    {
        $first = $this->submit($this->approvalGraph([], ['due' => ['amount' => 1, 'unit' => 'hours']]));
        $second = $this->submit($this->approvalGraph(), publish: false);
        $headers = $this->headersFor($this->managerA);

        $this->assertSame([$first->id, $second->id], array_column($this->getJson('/api/v1/approvals?sort=due', $headers)->json('data'), 'id'));
        $this->assertSame([$second->id, $first->id], array_column($this->getJson('/api/v1/approvals?sort=-received', $headers)->json('data'), 'id'));
        $this->assertCount(2, $this->getJson('/api/v1/approvals?type=core.test_request&company='.$this->acme->id, $headers)->json('data'));
        $this->getJson('/api/v1/approvals?type=nope', $headers)->assertUnprocessable();
        $this->assertCount(2, $this->getJson('/api/v1/approvals?search=Manager%20approves', $headers)->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/approvals?search=zzz', $headers)->json('data'));

        $this->inTenant(fn () => ApprovalRequest::query()->whereKey($first->id)->update(['due_at' => CarbonImmutable::now()->subMinute()]));
        $this->assertSame([$first->id], array_column($this->getJson('/api/v1/approvals?overdue=1', $headers)->json('data'), 'id'));

        // Oversight: the owner sees all; a manager without view_all is refused.
        $this->assertCount(2, $this->getJson('/api/v1/approvals?view=all', $this->headersFor())->assertOk()->json('data'));
        $this->getJson('/api/v1/approvals?view=all', $headers)->assertForbidden();

        $this->get('/api/v1/approvals?format=csv', $headers)->assertOk();
    }

    public function test_why_this_route_shows_fields_and_results_and_sentences_only_to_people_who_see_the_document(): void
    {
        $graph = $this->approvalGraph();
        $graph['nodes'][] = ['id' => 'big', 'type' => 'condition', 'name' => 'Total over KES 250,000?',
            'condition' => ['field' => 'total', 'op' => 'gt', 'value' => ['amount_minor' => '25000000', 'currency' => 'KES']]];
        $graph['nodes'][] = ['id' => 'cfo', 'type' => 'approval', 'name' => 'CFO approves', 'approval' => ['approver' => ['type' => 'role', 'role' => 'template:accountant']]];
        $graph['edges'] = [
            ['from' => 'start', 'to' => 'prepare'], ['from' => 'prepare', 'to' => 'big'],
            ['from' => 'big', 'to' => 'cfo', 'branch' => 'yes'], ['from' => 'big', 'to' => 'approve', 'branch' => 'no'],
            ['from' => 'cfo', 'to' => 'approve', 'branch' => 'approved'], ['from' => 'cfo', 'to' => 'rejected', 'branch' => 'rejected'],
            ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'], ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
        ];
        $plain = $this->person('approver', Scope::branch($this->branchA->id), 'Pat Plain');
        $graph['nodes'][2]['approval']['approver'] = ['type' => 'user', 'user_id' => $plain->id];
        $approval = $this->submit($graph);

        // The approver cannot see test documents (core.party.view): the steps taken only.
        $route = $this->getJson($this->approvalUrl($approval), $this->headersFor($plain))->assertOk()->json('data.route');
        $this->assertSame('big', $route[0]['node_id']);
        $this->assertSame('no', $route[0]['branch']);
        $this->assertSame('Total over KES 250,000?', $route[0]['node_name']);
        $this->assertNull($route[0]['checks']);
        $this->assertNull($route[0]['explanations']);
        $this->assertStringNotContainsString('total', json_encode($route));
        $this->assertStringNotContainsString('120,000', json_encode($route));

        // The owner sees the document: sentences from its current values.
        $owner = $this->getJson($this->approvalUrl($approval), $this->headersFor())->json('data.route');
        $this->assertSame([['branch' => 'no', 'field' => 'total', 'label' => __('workflow.columns.document_type'), 'op' => 'gt', 'passed' => false]], $owner[0]['checks']);
        $this->assertStringContainsString('KES 120,000.00', implode(' ', $owner[0]['explanations']));
    }
}
