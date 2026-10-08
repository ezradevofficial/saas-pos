<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalEmailToken;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Runtime\WorkflowEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * APR-01, APR-03, APR-06, APR-07: approving and rejecting.
 *
 * A decision is made on the decider's own pending assignment of the
 * current step, or as the active delegate of a pending approver (recorded
 * as on behalf of them). The requester can never decide (APR-07), not
 * even as someone's delegate. A rejection always needs a reason; an
 * approval needs a comment when the node requires a reason.
 *
 * When the step's mode is satisfied (ApprovalModes) the step's other
 * pending assignments close; an approved step of a sequential chain moves
 * to the next approver; otherwise the request is decided and the workflow
 * engine completes the node (`approved` or `rejected` path). The engine's
 * exit rules still apply: a blocked exit rolls the decision back.
 */
class ApprovalDecisions
{
    public const APPROVE = 'approve';

    public const REJECT = 'reject';

    public function __construct(
        private readonly ApprovalAccess $access,
        private readonly ApprovalRouting $routing,
        private readonly ApprovalClock $clock,
        private readonly ApprovalLog $log,
        private readonly ApprovalNotices $notices,
        private readonly WorkflowEngine $engine,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * @param  'approve'|'reject'  $action
     * @param  string  $via  web | bulk | email
     */
    public function decide(ApprovalRequest $request, User $by, string $action, ?string $comment, string $via = 'web'): ApprovalRequest
    {
        return $this->transaction(function () use ($request, $by, $action, $comment, $via) {
            $request = $this->lockOrFail($request);

            if (! $request->isPending()) {
                throw new ApiException(422, 'approval_not_pending', __('approvals.errors.not_pending'));
            }

            $acting = $this->access->acting($request, $by);

            if ($acting === null) {
                throw new ApiException(403, 'not_assignee', __('approvals.errors.not_assignee'));
            }

            [$assignment, $onBehalfOf] = $acting;
            $this->assertNotRequester($request, $by, $onBehalfOf);

            $comment = $comment === null || trim($comment) === '' ? null : trim($comment);

            if ($comment === null && ($action === self::REJECT || ($request->config['require_reason'] ?? false))) {
                throw new ApiException(422, 'reason_required', __('approvals.errors.reason_required'), ['comment' => [__('approvals.errors.reason_required')]]);
            }

            $status = $action === self::APPROVE ? ApprovalAssignment::APPROVED : ApprovalAssignment::REJECTED;
            $assignment->forceFill([
                'status' => $status,
                'decided_by' => $by->id,
                'on_behalf_of' => $onBehalfOf,
                'decided_at' => CarbonImmutable::now(),
                'comment' => $comment,
            ])->save();

            $this->log->action($request, $status, $by->id, $comment, ['via' => $via, 'step' => $request->step], $assignment->id, $onBehalfOf);
            $this->log->audit($action, $request, ['assignment' => $assignment->id, 'status' => ApprovalAssignment::PENDING], [
                'assignment' => $assignment->id, 'status' => $status, 'via' => $via, 'comment' => $comment,
            ], $onBehalfOf);
            $this->expireEmailLinks($assignment);

            $decisive = $assignment->source === ApprovalAssignment::SOURCE_ESCALATED ? $status : null;
            $this->settleStep($request, $by, $decisive, $comment);

            return $request->refresh();
        });
    }

    /**
     * APR-05: the final timeout decides the request (the system acts: no
     * user; no self-approval question arises).
     *
     * @param  'approve'|'reject'  $final
     */
    public function decideAutomatically(ApprovalRequest $request, string $final): void
    {
        $outcome = $final === self::APPROVE ? ApprovalRequest::APPROVED : ApprovalRequest::REJECTED;
        $this->closeStep($request);
        $this->log->action($request, 'auto_'.$outcome, null, null, ['step' => $request->step]);
        $this->finish($request, $outcome, null, null, auto: true);
    }

    /** Decide the step when its mode is satisfied; move a chain on or finish the request. */
    public function settleStep(ApprovalRequest $request, ?User $by, ?string $decisive = null, ?string $comment = null): void
    {
        // Escalated approvers decide alone ($decisive) and do not change the count of the step's own approvers.
        $rows = $request->assignments()->where('step', $request->step)->where('status', '!=', ApprovalAssignment::REASSIGNED)
            ->where('source', '!=', ApprovalAssignment::SOURCE_ESCALATED)->get();
        $outcome = ApprovalModes::outcome(
            $request->mode,
            $rows->where('status', ApprovalAssignment::APPROVED)->count(),
            $rows->where('status', ApprovalAssignment::REJECTED)->count(),
            $rows->where('status', '!=', ApprovalAssignment::CLOSED)->count(),
            $decisive,
        );

        if ($outcome === null) {
            return;
        }

        $this->closeStep($request);

        if ($outcome === ApprovalRequest::APPROVED && $request->step + 1 < $request->steps) {
            $this->advance($request);

            return;
        }

        $this->finish($request, $outcome, $by, $comment);
    }

    /** APR-01: the next approver of a sequential chain. */
    private function advance(ApprovalRequest $request): void
    {
        $request->forceFill([
            'step' => $request->step + 1,
            'level_started_at' => CarbonImmutable::now(),
            'escalation_level' => 0,
            'escalated_depth' => 0,
            'reminders_sent' => 0,
        ]);
        $this->clock->schedule($request);
        $request->save();

        $this->log->action($request, 'step', null, null, ['step' => $request->step]);
        $users = $this->routing->assignStep($request, $this->routing->subject($request));
        $this->notices->requested($request, $users);
    }

    private function finish(ApprovalRequest $request, string $outcome, ?User $by, ?string $comment, bool $auto = false): void
    {
        $request->forceFill([
            'status' => $outcome,
            'outcome' => $outcome,
            'auto_decided' => $auto,
            'decided_at' => CarbonImmutable::now(),
            'escalate_at' => null,
            'next_reminder_at' => null,
        ])->save();

        if ($auto) {
            $this->log->audit('auto_decide', $request, ['status' => ApprovalRequest::PENDING], ['status' => $outcome]);
        }

        $this->engine->completeNode($request->workflow()->firstOrFail(), $request->token_id, $outcome, $by);
        $this->notices->decided($request, $outcome, $by, $comment);
    }

    /** Pending assignments of the step close: the step is decided without them. */
    private function closeStep(ApprovalRequest $request): void
    {
        $pending = $request->assignments()->where('status', ApprovalAssignment::PENDING)->get();

        foreach ($pending as $assignment) {
            $assignment->forceFill(['status' => ApprovalAssignment::CLOSED])->save();
            $this->expireEmailLinks($assignment);
        }
    }

    public function assertNotRequester(ApprovalRequest $request, User $by, ?string $onBehalfOf = null): void
    {
        $excluded = $this->routing->excluded($request);

        if (in_array($by->id, $excluded, true) || ($onBehalfOf !== null && in_array($onBehalfOf, $excluded, true))) {
            throw new ApiException(403, 'self_approval', __('approvals.errors.self_approval'));
        }
    }

    public function expireEmailLinks(ApprovalAssignment $assignment): void
    {
        ApprovalEmailToken::query()->where('assignment_id', $assignment->id)->whereNull('used_at')
            ->where('expires_at', '>', CarbonImmutable::now())
            ->update(['expires_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * Lock the document's flow row, then the request (M4): the engine locks
     * the flow row first too, so a decision, a timer and a workflow move on
     * the same document always queue in the same order and never deadlock.
     */
    public function lock(ApprovalRequest|string $request): ?ApprovalRequest
    {
        $id = $request instanceof ApprovalRequest ? $request->id : $request;
        $workflowId = ApprovalRequest::query()->whereKey($id)->value('workflow_id');

        if ($workflowId === null) {
            return null;
        }

        DocumentWorkflow::query()->whereKey($workflowId)->lockForUpdate()->first();

        return ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();
    }

    /** lock(), for a request that must exist. */
    public function lockOrFail(ApprovalRequest $request): ApprovalRequest
    {
        return $this->lock($request) ?? throw new ApiException(404, 'not_found', __('core.errors.not_found'));
    }

    public function transaction(callable $fn): mixed
    {
        $this->tenants->require();

        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
