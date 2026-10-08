<?php

namespace Tests\Concerns;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Workflow\Models\DocumentWorkflow;
use Carbon\CarbonImmutable;

/**
 * Approvals tests (APR-01..APR-09): the workflow fixtures with the real
 * approvals service, people at branch A, B and the company, and a flow
 * with one approval node (configurable) between a `prepare` stage and the
 * approved / rejected ends. Wednesday 2026-10-07 10:00 Nairobi by default.
 */
trait BuildsApprovals
{
    use BuildsWorkflows;

    protected User $requester;

    protected User $managerA;

    protected User $managerB;

    protected User $accountant;

    protected function setUpApprovals(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07T07:00:00Z'));
        $this->setUpWorkflows(approvals: true);
        $this->requester = $this->person('cashier', Scope::location($this->locationA->id), 'Rita Requester');
        $this->managerA = $this->person('branch_manager', Scope::branch($this->branchA->id), 'Mary Manager A');
        $this->managerB = $this->person('branch_manager', Scope::branch($this->branchB->id), 'Ben Manager B');
        $this->accountant = $this->person('accountant', Scope::company($this->acme->id), 'Ann Accountant');
    }

    protected function person(string $template, Scope $scope, string $name): User
    {
        return $this->inTenant(function () use ($template, $scope, $name) {
            $user = $this->colleague($this->owner, ['name' => $name, 'locale' => 'en']);
            $this->assign($user, $this->roles->get($template), $scope);

            return $user;
        });
    }

    /** start → prepare (stage) → approve (approval) → approved | rejected. */
    protected function approvalGraph(array $approval = [], array $node = []): array
    {
        return [
            'nodes' => [
                ['id' => 'start', 'type' => 'start'],
                ['id' => 'prepare', 'type' => 'stage', 'name' => 'Prepare'],
                ['id' => 'approve', 'type' => 'approval', 'name' => 'Manager approves',
                    'approval' => [...['approver' => ['type' => 'branch_manager'], 'mode' => 'any'], ...$approval], ...$node],
                ['id' => 'approved', 'type' => 'end', 'outcome' => 'approved'],
                ['id' => 'rejected', 'type' => 'end', 'outcome' => 'rejected'],
            ],
            'edges' => [
                ['from' => 'start', 'to' => 'prepare'],
                ['from' => 'prepare', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'approved', 'branch' => 'approved'],
                ['from' => 'approve', 'to' => 'rejected', 'branch' => 'rejected'],
            ],
        ];
    }

    /** Publish $graph, start a request at branch A by the requester, move it to the approval; its approval request. */
    protected function submit(array $graph, array $values = [], ?User $by = null, bool $publish = true): ApprovalRequest
    {
        if ($publish) {
            $this->publishFlow($graph);
        }

        $id = $this->document(['total' => ['amount_minor' => '12000000', 'currency' => 'KES'], ...$values]);
        $workflow = $this->start($id, $by ?? $this->requester);
        $this->inTenant(fn () => $this->engine()->move($workflow, $this->owner));

        return $this->approvalOf($workflow);
    }

    protected function approvalOf(DocumentWorkflow $workflow): ApprovalRequest
    {
        return $this->inTenant(fn () => ApprovalRequest::query()->where('workflow_id', $workflow->id)->orderByRaw("status = 'pending' desc")->latest('received_at')->firstOrFail());
    }

    protected function fresh(ApprovalRequest $request): ApprovalRequest
    {
        return $this->inTenant(fn () => $request->fresh());
    }

    /** @return list<string> user ids of the step's pending approvers */
    protected function pendingApprovers(ApprovalRequest $request): array
    {
        return $this->inTenant(fn () => $request->assignments()->where('status', 'pending')->orderBy('user_id')->pluck('user_id')->all());
    }

    protected function approvalUrl(ApprovalRequest $request, string $suffix = ''): string
    {
        return '/api/v1/approvals/'.$request->id.$suffix;
    }
}
