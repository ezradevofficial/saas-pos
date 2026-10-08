<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalEmailToken;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Handlers\ApprovalStep;
use Carbon\CarbonImmutable;

/**
 * The approvals service's side of the workflow engine (APR-01..APR-09):
 * bound as the engine's ApprovalHandler by ApprovalsServiceProvider.
 *
 * - entered: a request is opened with the node's configuration as
 *   published in the document's flow version (APR-09: a document keeps its
 *   version, so an in-progress request keeps its configuration), the
 *   current step is resolved and assigned, timers are set and the
 *   approvers are notified;
 * - left: an open request closes (returned, cancelled, or expired when a
 *   parallel "any" join closed its branch), its pending assignments close
 *   and unused email links stop working;
 * - an approval node is completed only by approval decisions
 *   (ApprovalDecisions), never through the workflow move endpoint, so no
 *   stage role can bypass the approvers or the no-self-approval rule.
 */
class EngineApprovals implements ApprovalHandler
{
    public function __construct(
        private readonly ApprovalConfig $config,
        private readonly ApprovalRouting $routing,
        private readonly ApprovalClock $clock,
        private readonly ApprovalLog $log,
        private readonly ApprovalNotices $notices,
    ) {}

    public function validate(array $node, DocumentType $type): array
    {
        return $this->config->validate($node, $type);
    }

    public function entered(ApprovalStep $step): void
    {
        $config = ApprovalConfig::normalise($step->node);
        $summary = $step->type->summary($step->documentId());
        $now = CarbonImmutable::now();
        $amount = is_array($summary['amount'] ?? null) ? $summary['amount'] : null;

        $request = new ApprovalRequest([
            'workflow_id' => $step->workflow->id,
            'token_id' => $step->token->id,
            'version_id' => $step->workflow->version_id,
            'node_id' => $step->token->node_id,
            'node_name' => is_string($step->node['name'] ?? null) ? mb_substr($step->node['name'], 0, 255) : null,
            'document_type' => $step->type->key(),
            'document_id' => $step->documentId(),
            'company_id' => $step->scope->companyId,
            'branch_id' => $step->scope->branchId,
            'location_id' => $step->scope->locationId,
            'document_number' => is_string($summary['number'] ?? null) ? mb_substr($summary['number'], 0, 100) : null,
            'document_title' => is_string($summary['title'] ?? null) ? mb_substr($summary['title'], 0, 255) : null,
            'amount_minor' => $amount['amount_minor'] ?? null,
            'currency' => $amount === null ? null : $amount['currency'],
            // A flow the system started (no user) takes its requester from the type (L6).
            'requester_id' => $step->workflow->started_by ?? $step->type->requesterId($step->documentId()),
            'config' => $config,
            'mode' => $config['mode'],
            'step' => 0,
            'steps' => count($config['chain']),
            'status' => ApprovalRequest::PENDING,
            'received_at' => $now,
            'due_at' => $step->token->due_at,
            'level_started_at' => $now,
            'escalation_level' => 0,
            'escalated_depth' => 0,
            'reminders_sent' => 0,
        ]);

        $subject = $this->routing->subject($request, $step->node);

        // A type naming only a location or branch: the places above it come from the database.
        foreach (['company', 'branch'] as $level) {
            if ($request->{$level.'_id'} === null && ($link = $subject->at($level)) !== null) {
                $request->{$level.'_id'} = substr($link, strlen($level) + 1);
            }
        }

        $this->clock->schedule($request);
        $request->save();

        $this->log->action($request, 'requested', $step->workflow->started_by, null, ['version_id' => $request->version_id]);
        $users = $this->routing->assignStep($request, $subject);
        $this->log->audit('request', $request, null, ['node' => $request->node_id, 'approvers' => $users, 'blocked_reason' => $request->blocked_reason]);
        $this->notices->requested($request, $users);
    }

    public function left(ApprovalStep $step, string $why): void
    {
        $request = ApprovalRequest::query()->where('token_id', $step->token->id)->lockForUpdate()->first();

        if ($request === null) {
            return;
        }

        $assignments = $request->assignments()->where('status', ApprovalAssignment::PENDING);
        $ids = $assignments->pluck('id')->all();
        $request->assignments()->whereKey($ids)->update(['status' => ApprovalAssignment::CLOSED, 'updated_at' => CarbonImmutable::now()]);
        ApprovalEmailToken::query()->whereIn('assignment_id', $request->assignments()->pluck('id'))->whereNull('used_at')
            ->where('expires_at', '>', CarbonImmutable::now())->update(['expires_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if (! $request->isPending()) {
            return;
        }

        $request->forceFill([
            'status' => match ($why) {
                'returned' => ApprovalRequest::RETURNED,
                'joined' => ApprovalRequest::EXPIRED,
                default => ApprovalRequest::CANCELLED,
            },
            'decided_at' => CarbonImmutable::now(),
            'escalate_at' => null,
            'next_reminder_at' => null,
        ])->save();

        $this->log->action($request, 'closed', null, null, ['why' => $why]);
    }

    public function allowsManualCompletion(ApprovalStep $step): bool
    {
        return false;
    }

    public function holders(ApprovalStep $step): ?array
    {
        $request = ApprovalRequest::query()->where('token_id', $step->token->id)->first();

        if ($request === null) {
            return null;
        }

        return [
            'roles' => [],
            'users' => $request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)->pluck('user_id')->all(),
            'blocked' => $request->blocked_reason,
            'approval_id' => $request->id,
        ];
    }
}
