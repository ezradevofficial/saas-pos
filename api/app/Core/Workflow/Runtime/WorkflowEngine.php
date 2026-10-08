<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Audit\Auditor;
use App\Core\Automation\Runtime\FieldVisibility;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Company;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Conditions\ConditionCheck;
use App\Core\Workflow\Conditions\ConditionDescriber;
use App\Core\Workflow\Conditions\ConditionEvaluator;
use App\Core\Workflow\Conditions\ConditionResult;
use App\Core\Workflow\Definitions\FlowDefinitions;
use App\Core\Workflow\Definitions\FlowGraph;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Events\WorkflowCancelled;
use App\Core\Workflow\Events\WorkflowCompleted;
use App\Core\Workflow\Events\WorkflowStageEntered;
use App\Core\Workflow\Events\WorkflowStageLeft;
use App\Core\Workflow\Handlers\ActionContext;
use App\Core\Workflow\Handlers\ActionHandlers;
use App\Core\Workflow\Handlers\ApprovalHandler;
use App\Core\Workflow\Handlers\ApprovalStep;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\DocumentWorkflowLink;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs documents through their flows (WF-04..WF-11, APR-09). Modules call
 * start() when a document is submitted; people move, return and cancel
 * through the API; the approvals service calls completeNode().
 *
 * Every operation runs in one transaction with the document's flow row
 * locked: a blocked move (a failed entry or exit condition, or a stage
 * refusing the mover) changes nothing. Automatic nodes (conditions,
 * parallel splits, joins, actions) are passed through at once; the
 * document waits at stages and approvals (tokens). Domain events are
 * dispatched after commit.
 *
 * Semantics:
 * - entry condition: a mandatory stage (the default) blocks; an optional
 *   one (`mandatory: false`) is skipped and the flow goes on;
 * - exit condition: checked when the stage is completed;
 * - a person moving a document into a stage needs its enter roles; out of
 *   a stage, its exit roles (WF-08); start() is the module's call and
 *   checks neither;
 * - parallel split: one position per branch; join `all` continues when
 *   every branch arrived, `any` at the first (the other branches close);
 * - return: back to a stage or approval the document passed, with a
 *   reason (entry conditions are not checked again);
 * - cancel: with a reason; documents the flow created are kept or
 *   cancelled per the action's `on_cancel` (WF-11).
 */
class WorkflowEngine
{
    public function __construct(
        private readonly DocumentTypeRegistry $types,
        private readonly FlowDefinitions $definitions,
        private readonly ConditionEvaluator $conditions,
        private readonly ConditionDescriber $describer,
        private readonly ActionHandlers $actions,
        private readonly ApprovalHandler $approvals,
        private readonly BusinessCalendar $calendar,
        private readonly StagePermissions $permissions,
        private readonly Auditor $auditor,
        private readonly TenantContext $tenants,
        private readonly StageTimers $timers,
        private readonly WorkflowAccess $access,
        private readonly FieldVisibility $visibility,
    ) {}

    /**
     * Start $type's flow for a document (the owning module's call, after
     * its own permission checks). The flow is the published version for
     * the document's company, else for every company, else the type's
     * default (published as version 1, WF-02).
     */
    public function start(string $type, string $documentId, ?User $by): DocumentWorkflow
    {
        $documentType = $this->types->find($type) ?? throw new ApiException(422, 'unknown_document_type', __('workflow.errors.unknown_document_type'));
        $scope = $documentType->scope($documentId) ?? throw new ApiException(404, 'not_found', __('core.errors.not_found'));

        try {
            return $this->startIn($documentType, $documentId, $scope, $by);
        } catch (UniqueConstraintViolationException) {
            // Another request started this document's flow at the same moment.
            throw new ApiException(422, 'workflow_running', __('workflow.errors.workflow_running'));
        }
    }

    private function startIn(DocumentType $documentType, string $documentId, DocumentScope $scope, ?User $by): DocumentWorkflow
    {
        return $this->transaction(function () use ($documentType, $documentId, $scope, $by) {
            if ($this->running($documentType->key(), $documentId, lock: true) !== null) {
                throw new ApiException(422, 'workflow_running', __('workflow.errors.workflow_running'));
            }

            $version = $this->definitions->publishedFor($documentType->key(), $scope->companyId)
                ?? $this->definitions->adoptDefault($documentType, $scope->companyId === null ? null : Company::query()->find($scope->companyId))
                ?? throw new ApiException(422, 'workflow_not_configured', __('workflow.errors.workflow_not_configured'));

            $workflow = DocumentWorkflow::create([
                'document_type' => $documentType->key(),
                'document_id' => $documentId,
                'company_id' => $scope->companyId,
                'version_id' => $version->id,
                'status' => DocumentWorkflow::RUNNING,
                'started_by' => $by?->id,
                'started_at' => CarbonImmutable::now(),
            ]);

            $run = new Run($workflow, $version->flow(), $documentType, $scope, $by, checkEnter: false);
            $this->event($run, 'started', null, ['version' => $version->version]);
            $this->enter($run, $run->flow->start(), []);
            $this->settle($run);

            $this->auditor->record('core.workflow.start', $workflow, null, [
                'document_type' => $workflow->document_type, 'document_id' => $documentId, 'version' => $version->version,
            ]);

            return $workflow->refresh();
        });
    }

    /** The document's running flow, else its latest one. */
    public function current(string $type, string $documentId): ?DocumentWorkflow
    {
        return DocumentWorkflow::query()
            ->where('document_type', $type)
            ->where('document_id', $documentId)
            ->orderByRaw("status = 'running' desc")
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A person completes the stage (or approval) at $nodeId, or the only
     * stage the document is at (WF-04, WF-08). For an approval, $outcome is
     * approved (default) or rejected.
     */
    public function move(DocumentWorkflow $workflow, User $by, ?string $nodeId = null, ?string $outcome = null): DocumentWorkflow
    {
        return $this->transaction(function () use ($workflow, $by, $nodeId, $outcome) {
            $run = $this->open($workflow, $by, checkEnter: true);
            $token = $this->activeToken($run, $nodeId);
            $node = $run->flow->node($token->node_id);

            if ($node['type'] === 'approval') {
                $outcome ??= 'approved';

                if (! $this->approvals->allowsManualCompletion($this->step($run, $token))) {
                    throw new WorkflowBlocked('approval_pending', __('workflow.errors.approval_pending', ['stage' => $run->flow->name($token->node_id)]), [], $token->node_id, 403);
                }
            } elseif ($outcome !== null) {
                throw new ApiException(422, 'outcome_not_allowed', __('workflow.errors.outcome_not_allowed'));
            }

            if (! $this->permissions->allows($by, $node, 'exit', $run->scope, $run->type)) {
                throw new WorkflowBlocked('stage_forbidden', __('workflow.errors.stage_forbidden', ['stage' => $run->flow->name($token->node_id)]), [], $token->node_id, 403);
            }

            $this->complete($run, $token, $outcome);
            $this->settle($run);
            $this->auditor->record('core.workflow.move', $run->workflow, null, array_filter([
                'node' => $token->node_id, 'outcome' => $outcome,
            ], fn ($v) => $v !== null));

            return $run->workflow->refresh();
        });
    }

    /**
     * APR-01: the approvals service completes an approval node with its
     * decision (approved or rejected). Permissions are the caller's
     * business; the exit condition still applies. $by is the decider (null
     * for an automatic decision).
     */
    public function completeNode(DocumentWorkflow $workflow, string $tokenId, string $outcome, ?User $by): DocumentWorkflow
    {
        return $this->transaction(function () use ($workflow, $tokenId, $outcome, $by) {
            $run = $this->open($workflow, $by, checkEnter: false);
            $token = DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->whereKey($tokenId)
                ->where('status', DocumentWorkflowToken::ACTIVE)->first()
                ?? throw new ApiException(422, 'stage_not_current', __('workflow.errors.stage_not_current'));

            $this->complete($run, $token, $outcome);
            $this->settle($run);
            $this->auditor->record('core.workflow.move', $run->workflow, null, ['node' => $token->node_id, 'outcome' => $outcome]);

            return $run->workflow->refresh();
        });
    }

    /**
     * WF-11: send the document back to a stage it passed, with a reason.
     *
     * Only the positions of the branch the target is in are closed (every
     * open position when the target is outside any parallel step): a
     * sibling branch waiting at its join stays. The returner needs the exit
     * rights of every active position closed, or the type's act permission,
     * so one branch's holder cannot pull back another branch's work.
     *
     * `$authorised`: the approvals service already checked $by may act on
     * the approval node being returned from (APR-03, an approver returning
     * for changes); the stage rights are then not checked again.
     */
    public function returnTo(DocumentWorkflow $workflow, User $by, string $nodeId, string $reason, bool $authorised = false): DocumentWorkflow
    {
        return $this->transaction(function () use ($workflow, $by, $nodeId, $reason, $authorised) {
            $run = $this->open($workflow, $by, checkEnter: false);
            $target = $run->flow->node($nodeId);

            $passed = $target !== null && in_array($target['type'], FlowGraph::HOLDING, true)
                ? DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)->where('node_id', $nodeId)
                    ->where('status', DocumentWorkflowToken::DONE)->latest('entered_at')->first()
                : null;

            if ($passed === null) {
                throw new ApiException(422, 'return_target', __('workflow.errors.return_target'));
            }

            $branch = $passed->groups ?? [];
            $affected = $this->tokens($run, [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING])
                ->filter(fn (DocumentWorkflowToken $t) => array_slice($t->groups ?? [], 0, count($branch)) === $branch)
                ->values();
            $active = $affected->where('status', DocumentWorkflowToken::ACTIVE)->values();
            $later = $run->flow->reachableFrom($nodeId);

            if ($affected->isEmpty()) {
                // The branch already joined: nothing of it is open any more.
                $code = $branch === [] ? 'return_target' : 'return_inside_parallel';

                throw new ApiException(422, $code, __('workflow.errors.'.$code));
            }

            if (! $affected->every(fn (DocumentWorkflowToken $t) => in_array($t->node_id, $later, true))) {
                throw new ApiException(422, 'return_target', __('workflow.errors.return_target'));
            }

            $mayAct = $authorised || $by->can($run->type->actPermission(), $run->scope->scope());
            $refused = $mayAct ? null : ($active->isEmpty() ? $affected->first() : $active->first(
                fn (DocumentWorkflowToken $t) => ! $this->permissions->allows($by, $run->flow->node($t->node_id), 'exit', $run->scope, $run->type),
            ));

            if ($refused !== null) {
                throw new WorkflowBlocked('stage_forbidden', __('workflow.errors.stage_forbidden', ['stage' => $run->flow->name($refused->node_id)]), [], $refused->node_id, 403);
            }

            $from = [];

            foreach ($affected as $token) {
                $from[] = $token->node_id;
                $this->leave($run, $token, DocumentWorkflowToken::CANCELLED, 'returned', null, $reason);
            }

            $this->event($run, 'returned', $nodeId, ['from' => $from], $reason);
            $this->hold($run, $nodeId, $branch);
            $this->settle($run);
            $this->auditor->record('core.workflow.return', $run->workflow, ['nodes' => $from], ['node' => $nodeId, 'reason' => $reason]);

            return $run->workflow->refresh();
        });
    }

    /**
     * WF-11: cancel the document's flow with a reason. People need the
     * exit roles of a stage the document is at (or the type's act
     * permission); $by null is the module or the system.
     */
    public function cancel(DocumentWorkflow $workflow, ?User $by, string $reason): DocumentWorkflow
    {
        return $this->transaction(function () use ($workflow, $by, $reason) {
            $run = $this->open($workflow, $by, checkEnter: false);
            $open = $this->tokens($run, [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING]);

            if ($by !== null) {
                $allowed = $by->can($run->type->actPermission(), $run->scope->scope())
                    || $open->contains(fn (DocumentWorkflowToken $t) => $t->status === DocumentWorkflowToken::ACTIVE
                        && $this->permissions->allows($by, $run->flow->node($t->node_id), 'exit', $run->scope, $run->type));

                if (! $allowed) {
                    throw new ApiException(403, 'forbidden', __('workflow.errors.cancel_forbidden'));
                }
            }

            foreach ($open as $token) {
                $this->leave($run, $token, DocumentWorkflowToken::CANCELLED, 'cancelled', null, $reason);
            }

            $cancelled = [];
            $kept = [];

            foreach (DocumentWorkflowLink::query()->where('workflow_id', $workflow->id)->where('on_cancel', 'cancel')->whereNull('cancelled_at')->get() as $link) {
                $target = $this->types->find($link->target_type);

                // The target's module is no longer active: its document cannot be
                // reached; the flow is still cancelled and the history says so.
                if ($target === null) {
                    $kept[] = ['type' => $link->target_type, 'document_id' => $link->target_document_id, 'reason' => 'module_inactive'];

                    continue;
                }

                $target->cancelDocument($link->target_document_id, $reason, $by);
                $link->forceFill(['cancelled_at' => CarbonImmutable::now()])->save();
                $cancelled[] = ['type' => $link->target_type, 'document_id' => $link->target_document_id];
            }

            $run->workflow->forceFill([
                'status' => DocumentWorkflow::CANCELLED,
                'cancelled_at' => CarbonImmutable::now(),
                'cancelled_by' => $by?->id,
                'cancel_reason' => $reason,
            ])->save();

            $this->event($run, 'cancelled', null, ['cancelled_documents' => $cancelled, 'not_cancelled_documents' => $kept], $reason);
            WorkflowCancelled::dispatch($workflow->tenant_id, $workflow->id, $workflow->document_type, $workflow->document_id, $reason, $by?->id);
            $this->auditor->record('core.workflow.cancel', $run->workflow, ['status' => DocumentWorkflow::RUNNING], [
                'status' => DocumentWorkflow::CANCELLED, 'reason' => $reason, 'cancelled_documents' => $cancelled,
            ]);

            return $run->workflow->refresh();
        });
    }

    /**
     * WF-10: where the document is, who holds it, for how long, and its
     * history. `can_move` tells $viewer whether the move endpoint would
     * let them complete each stage (the API checks again).
     *
     * @return array<string, mixed>
     */
    public function status(DocumentWorkflow $workflow, ?User $viewer = null): array
    {
        $type = $this->types->get($workflow->document_type);
        $version = WorkflowVersion::query()->findOrFail($workflow->version_id);
        $flow = $version->flow();
        $scope = $type->scope($workflow->document_id) ?? new DocumentScope($workflow->company_id);
        $now = CarbonImmutable::now();
        $run = new Run($workflow, $flow, $type, $scope, $viewer, false);

        $current = DocumentWorkflowToken::query()->where('workflow_id', $workflow->id)
            ->whereIn('status', [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING])
            ->orderBy('entered_at')->orderBy('id')->get()
            ->map(function (DocumentWorkflowToken $token) use ($workflow, $flow, $scope, $type, $now, $viewer, $run) {
                $node = $flow->node($token->node_id) ?? ['type' => 'stage'];
                $holders = null;

                if ($token->status === DocumentWorkflowToken::ACTIVE) {
                    $handled = $node['type'] === 'approval' ? $this->approvals->holders($this->step($run, $token)) : null;
                    // The handler may add why nobody holds the step (`blocked`) and its own id (`approval_id`).
                    $holders = $handled === null
                        ? $this->permissions->holders($node, $scope, $type)
                        : [
                            ...$this->permissions->holders($node, $scope, $type, $handled['roles'], $handled['users']),
                            ...array_intersect_key($handled, array_flip(['blocked', 'approval_id'])),
                        ];
                }

                return [
                    'token_id' => $token->id,
                    'node_id' => $token->node_id,
                    'name' => $flow->name($token->node_id),
                    'type' => $node['type'],
                    'status' => $token->status,
                    'entered_at' => $token->entered_at?->toIso8601ZuluString(),
                    'due_at' => $token->due_at?->toIso8601ZuluString(),
                    'overdue' => $token->due_at !== null && $token->due_at->lessThan($now),
                    'seconds_in_stage' => (int) $token->entered_at->diffInSeconds($now, true),
                    'holders' => $holders,
                    'can_move' => $viewer !== null && $token->status === DocumentWorkflowToken::ACTIVE && $workflow->isRunning()
                        && ($node['type'] !== 'approval' || $this->approvals->allowsManualCompletion($this->step($run, $token)))
                        && $this->permissions->allows($viewer, $node, 'exit', $scope, $type),
                ];
            })->values()->all();

        $events = DocumentWorkflowEvent::query()->where('workflow_id', $workflow->id)
            ->orderBy('occurred_at')->orderBy('id')->get();
        $names = User::query()->whereKey(array_values(array_unique(array_filter([
            ...$events->pluck('user_id')->all(), $workflow->started_by, $workflow->cancelled_by,
        ]))))->pluck('name', 'id');

        $user = fn (?string $id) => $id === null ? null : ['id' => $id, 'name' => $names[$id] ?? null];

        return [
            'id' => $workflow->id,
            'document_type' => $workflow->document_type,
            'document_id' => $workflow->document_id,
            'status' => $workflow->status,
            'outcome' => $workflow->outcome,
            'version' => ['id' => $version->id, 'number' => $version->version],
            'started_at' => $workflow->started_at?->toIso8601ZuluString(),
            'started_by' => $user($workflow->started_by),
            'completed_at' => $workflow->completed_at?->toIso8601ZuluString(),
            'cancelled_at' => $workflow->cancelled_at?->toIso8601ZuluString(),
            'cancelled_by' => $user($workflow->cancelled_by),
            'cancel_reason' => $workflow->cancel_reason,
            'current' => $current,
            'history' => $events->map(fn (DocumentWorkflowEvent $e) => [
                'type' => $e->type,
                'node_id' => $e->node_id,
                'node_name' => $e->node_id === null ? null : $flow->name($e->node_id),
                'user' => $user($e->user_id),
                'reason' => $e->reason,
                'data' => $e->data,
                'occurred_at' => $e->occurred_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'created_documents' => DocumentWorkflowLink::query()->where('workflow_id', $workflow->id)->orderBy('created_at')->get()
                ->map(fn (DocumentWorkflowLink $l) => [
                    'node_id' => $l->node_id, 'mapping' => $l->mapping, 'type' => $l->target_type,
                    'document_id' => $l->target_document_id, 'on_cancel' => $l->on_cancel,
                    'cancelled_at' => $l->cancelled_at?->toIso8601ZuluString(),
                ])->values()->all(),
        ];
    }

    /** WF-09: active positions past their due time (StageTimers and the approvals service act on their own timers). */
    public function overdue(?\DateTimeInterface $at = null)
    {
        return DocumentWorkflowToken::query()->overdue($at)->with('workflow');
    }

    // ---- traversal --------------------------------------------------------

    /** Arrive at $nodeId (inside the parallel $groups) and go on until the document waits. */
    private function enter(Run $run, ?string $nodeId, array $groups): void
    {
        if ($nodeId === null || $run->inClosedGroup($groups)) {
            return;
        }

        $node = $run->flow->node($nodeId);

        switch ($node['type']) {
            case 'start':
                $this->enter($run, $run->flow->next($nodeId), $groups);

                return;

            case 'stage':
            case 'approval':
                $result = $this->evaluate($run, $node['entry'] ?? null);

                if (! $result->passed) {
                    if (($node['mandatory'] ?? true) !== false) {
                        throw new WorkflowBlocked('entry_blocked', __('workflow.errors.entry_blocked', ['stage' => $run->flow->name($nodeId)]), $this->reasons($run, $result), $nodeId);
                    }

                    $this->event($run, 'skipped', $nodeId, ['condition' => $result->outline()]);
                    $this->enter($run, $run->flow->next($nodeId, $node['type'] === 'approval' ? 'approved' : null), $groups);

                    return;
                }

                if ($run->checkEnter && $run->user !== null && ! $this->permissions->allows($run->user, $node, 'enter', $run->scope, $run->type)) {
                    throw new WorkflowBlocked('stage_enter_forbidden', __('workflow.errors.stage_enter_forbidden', ['stage' => $run->flow->name($nodeId)]), [], $nodeId, 403);
                }

                $this->hold($run, $nodeId, $groups);

                return;

            case 'condition':
                [$branch, $results] = $this->branch($run, $node);
                $this->event($run, 'condition', $nodeId, ['branch' => $branch, 'results' => $results]);
                $this->enter($run, $run->flow->next($nodeId, $branch), $groups);

                return;

            case 'parallel':
                $group = (string) Str::uuid7();
                $this->event($run, 'split', $nodeId, ['group' => $group]);

                // Each branch is "{group}#{n}", so a branch's positions can be told from its siblings'.
                foreach ($run->flow->outgoing($nodeId) as $i => $edge) {
                    $this->enter($run, $edge['to'], [...$groups, $group.'#'.$i]);
                }

                return;

            case 'join':
                $this->join($run, $nodeId, $node, $groups);

                return;

            case 'action':
                $handler = $this->actions->find((string) $node['action'])
                    ?? throw new WorkflowBlocked('action_unavailable', __('workflow.errors.action_unavailable', ['stage' => $run->flow->name($nodeId)]), [], $nodeId);
                $result = $handler->run(new ActionContext($run->workflow, $node, $run->type, $run->scope, $run->values(), $run->user));
                $this->event($run, 'action', $nodeId, ['action' => $node['action'], 'result' => $result]);
                $this->enter($run, $run->flow->next($nodeId), $groups);

                return;

            case 'end':
                $run->workflow->outcome = is_string($node['outcome'] ?? null) ? $node['outcome'] : 'completed';

                return;
        }
    }

    /** The document waits at a stage or approval: a new active position with its due time. */
    private function hold(Run $run, string $nodeId, array $groups): void
    {
        $node = $run->flow->node($nodeId);
        $now = CarbonImmutable::now();
        $due = $node['due'] ?? null;
        $dueAt = is_array($due)
            ? $this->calendar->due($now, (int) $due['amount'], (string) $due['unit'], $this->calendar->forCompany($run->scope->companyId))
            : null;

        $token = DocumentWorkflowToken::create([
            'workflow_id' => $run->workflow->id,
            'node_id' => $nodeId,
            'status' => DocumentWorkflowToken::ACTIVE,
            'groups' => $groups,
            'entered_at' => $now,
            'due_at' => $dueAt,
            // WF-09: a plain stage's reminders, overdue notice and escalation (StageTimers).
            'next_timer_at' => $this->timers->first($node, $now, $dueAt, $run->scope->companyId),
            'entered_by' => $run->user?->id,
        ]);

        $this->event($run, 'entered', $nodeId, ['due_at' => $token->due_at?->toIso8601ZuluString()]);
        WorkflowStageEntered::dispatch(
            $run->workflow->tenant_id, $run->workflow->id, $run->workflow->document_type, $run->workflow->document_id,
            $nodeId, $node['type'], $run->workflow->version_id, $run->user?->id,
        );

        if ($node['type'] === 'approval') {
            $this->approvals->entered($this->step($run, $token));
        }
    }

    /** Leave a stage or approval as completed (exit condition checked) and follow the edge. */
    private function complete(Run $run, DocumentWorkflowToken $token, ?string $outcome): void
    {
        $node = $run->flow->node($token->node_id);
        $branch = null;

        if ($node['type'] === 'approval') {
            if (! in_array($outcome, ['approved', 'rejected'], true)) {
                throw new ApiException(422, 'outcome_not_allowed', __('workflow.errors.outcome_not_allowed'));
            }

            if ($run->flow->next($token->node_id, $outcome) === null) {
                throw new ApiException(422, 'no_rejected_path', __('workflow.errors.no_rejected_path', ['stage' => $run->flow->name($token->node_id)]));
            }

            $branch = $outcome;
        }

        // A rejection leaves without meeting the exit rules: they guard going forward.
        if ($outcome !== 'rejected') {
            $result = $this->evaluate($run, $node['exit'] ?? null);

            if (! $result->passed) {
                throw new WorkflowBlocked('exit_blocked', __('workflow.errors.exit_blocked', ['stage' => $run->flow->name($token->node_id)]), $this->reasons($run, $result), $token->node_id);
            }
        }

        $this->leave($run, $token, DocumentWorkflowToken::DONE, 'completed', $outcome);
        $this->enter($run, $run->flow->next($token->node_id, $branch), $token->groups ?? []);
    }

    /** Close a position: history, the approval handler, the StageLeft event. */
    private function leave(Run $run, DocumentWorkflowToken $token, string $status, string $how, ?string $outcome = null, ?string $reason = null): void
    {
        $node = $run->flow->node($token->node_id) ?? ['type' => 'stage'];
        $token->forceFill(['status' => $status, 'left_at' => CarbonImmutable::now(), 'left_by' => $run->user?->id])->save();

        if (! in_array($node['type'], FlowGraph::HOLDING, true)) {
            return;
        }

        $this->event($run, 'left', $token->node_id, array_filter(['how' => $how, 'outcome' => $outcome]), $how === 'completed' ? null : $reason);

        if ($node['type'] === 'approval') {
            $this->approvals->left($this->step($run, $token), $how);
        }

        WorkflowStageLeft::dispatch(
            $run->workflow->tenant_id, $run->workflow->id, $run->workflow->document_type, $run->workflow->document_id,
            $token->node_id, $node['type'], $how, $outcome, $run->user?->id,
        );
    }

    /** WF-06: arrive at a join; continue when the branches it waits for are in. */
    private function join(Run $run, string $nodeId, array $node, array $groups): void
    {
        $branch = end($groups);

        if ($branch === false) {
            return;
        }

        $group = Run::groupOf($branch);

        $outer = array_slice($groups, 0, -1);

        DocumentWorkflowToken::create([
            'workflow_id' => $run->workflow->id,
            'node_id' => $nodeId,
            'status' => DocumentWorkflowToken::WAITING,
            'groups' => $groups,
            'entered_at' => CarbonImmutable::now(),
            'entered_by' => $run->user?->id,
        ]);

        $arrived = DocumentWorkflowToken::query()->where('workflow_id', $run->workflow->id)->where('node_id', $nodeId)
            ->where('status', DocumentWorkflowToken::WAITING)->get()
            ->filter(fn (DocumentWorkflowToken $t) => Run::groupOf((string) last($t->groups ?? [])) === $group);
        $branches = count($run->flow->outgoing((string) $node['split']));
        $mode = $node['mode'] ?? 'all';

        if ($mode === 'all' && $arrived->count() < $branches) {
            return;
        }

        foreach ($arrived as $token) {
            $token->forceFill(['status' => DocumentWorkflowToken::DONE, 'left_at' => CarbonImmutable::now(), 'left_by' => $run->user?->id])->save();
        }

        if ($mode === 'any') {
            // The other branches close: their positions leave as joined.
            $run->closedGroups[$group] = true;

            foreach ($this->tokens($run, [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING]) as $token) {
                if (in_array($group, array_map(Run::groupOf(...), $token->groups ?? []), true)) {
                    $this->leave($run, $token, DocumentWorkflowToken::CANCELLED, 'joined');
                }
            }
        }

        $this->event($run, 'joined', $nodeId, ['group' => $group, 'mode' => $mode, 'arrived' => $arrived->count(), 'branches' => $branches]);
        $this->enter($run, $run->flow->next($nodeId), $outer);
    }

    /**
     * WF-05: the branch a condition node takes. A yes/no node evaluates its
     * `condition`; a labelled one takes its first branch whose condition
     * holds, else `else`.
     *
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function branch(Run $run, array $node): array
    {
        if (isset($node['branches']) && is_array($node['branches'])) {
            $results = [];

            foreach ($node['branches'] as $branch) {
                $result = $this->evaluate($run, $branch['condition'] ?? null);
                $results[] = ['branch' => $branch['key'], ...$result->outline()];

                if ($result->passed) {
                    return [$branch['key'], $results];
                }
            }

            return ['else', $results];
        }

        $result = $this->evaluate($run, $node['condition'] ?? null);

        return [$result->passed ? 'yes' : 'no', [['branch' => $result->passed ? 'yes' : 'no', ...$result->outline()]]];
    }

    /** After an operation: a flow with nobody left at any stage is complete. */
    private function settle(Run $run): void
    {
        $workflow = $run->workflow;

        if (! $workflow->isRunning() || $this->tokens($run, [DocumentWorkflowToken::ACTIVE, DocumentWorkflowToken::WAITING])->isNotEmpty()) {
            if ($workflow->isDirty()) {
                $workflow->save();
            }

            return;
        }

        $outcome = $workflow->outcome ?? 'completed';
        $workflow->forceFill(['status' => DocumentWorkflow::COMPLETED, 'outcome' => $outcome, 'completed_at' => CarbonImmutable::now()])->save();
        $this->event($run, 'completed', null, ['outcome' => $outcome]);
        WorkflowCompleted::dispatch($workflow->tenant_id, $workflow->id, $workflow->document_type, $workflow->document_id, $outcome, $run->user?->id);
    }

    // ---- helpers -----------------------------------------------------------

    /** Lock the flow row and load what an operation needs; only running flows move. */
    private function open(DocumentWorkflow $workflow, ?User $by, bool $checkEnter): Run
    {
        $locked = DocumentWorkflow::query()->whereKey($workflow->id)->lockForUpdate()->firstOrFail();

        if (! $locked->isRunning()) {
            throw new ApiException(422, 'workflow_not_running', __('workflow.errors.workflow_not_running'));
        }

        $type = $this->types->find($locked->document_type) ?? throw new ApiException(404, 'not_found', __('core.errors.not_found'));
        $scope = $type->scope($locked->document_id) ?? throw new ApiException(404, 'not_found', __('core.errors.not_found'));
        $version = WorkflowVersion::query()->findOrFail($locked->version_id);

        return new Run($locked, $version->flow(), $type, $scope, $by, $checkEnter);
    }

    private function running(string $type, string $documentId, bool $lock = false): ?DocumentWorkflow
    {
        return DocumentWorkflow::query()->where('document_type', $type)->where('document_id', $documentId)
            ->where('status', DocumentWorkflow::RUNNING)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function activeToken(Run $run, ?string $nodeId): DocumentWorkflowToken
    {
        $active = $this->tokens($run, [DocumentWorkflowToken::ACTIVE]);

        if ($nodeId !== null) {
            return $active->firstWhere('node_id', $nodeId)
                ?? throw new ApiException(422, 'stage_not_current', __('workflow.errors.stage_not_current'));
        }

        if ($active->count() !== 1) {
            throw new ApiException(422, 'choose_stage', __('workflow.errors.choose_stage'), [], ['nodes' => $active->pluck('node_id')->all()]);
        }

        return $active->first();
    }

    /** @return Collection<int, DocumentWorkflowToken> */
    private function tokens(Run $run, array $statuses)
    {
        return DocumentWorkflowToken::query()->where('workflow_id', $run->workflow->id)->whereIn('status', $statuses)
            ->orderBy('entered_at')->orderBy('id')->get();
    }

    private function evaluate(Run $run, mixed $condition): ConditionResult
    {
        return $this->conditions->evaluate(
            is_array($condition) ? $condition : null,
            is_array($condition) && $condition !== [] ? $run->values() : [],
            $run->type->fieldsByName(),
            $this->timezone($run),
        );
    }

    /**
     * H3 (RBAC-05): why a move is blocked, for the person moving. Rules on
     * fields their field rules hide, or every rule when they cannot see the
     * document, read only "A rule you can't see was not met." (no values).
     * The system (no person) gets every reason.
     *
     * @return list<string>
     */
    private function reasons(Run $run, ConditionResult $result): array
    {
        $fields = $run->type->fieldsByName();
        $user = $run->user;

        if ($user === null) {
            return $this->describer->reasons($result, $fields, $this->timezone($run));
        }

        if (! $this->access->seesDocument($user, $run->type, $run->scope)) {
            return $result->failures === [] ? [] : [__('workflow.errors.hidden_rule')];
        }

        $hidden = $this->visibility->hidden($user, $run->type);
        $visible = array_values(array_filter($result->failures, fn (ConditionCheck $c) => ! in_array($c->field, $hidden, true) && ! in_array($c->other, $hidden, true)));
        $reasons = $this->describer->reasons(new ConditionResult($result->passed, $result->checks, $visible), $fields, $this->timezone($run));

        if (count($visible) < count($result->failures)) {
            $reasons[] = __('workflow.errors.hidden_rule');
        }

        return $reasons;
    }

    private function timezone(Run $run): string
    {
        return $run->timezone ??= $this->calendar->forCompany($run->scope->companyId)->timezone;
    }

    private function step(Run $run, DocumentWorkflowToken $token): ApprovalStep
    {
        return new ApprovalStep($run->workflow, $token, $run->flow->node($token->node_id) ?? [], $run->type, $run->scope);
    }

    private function event(Run $run, string $type, ?string $nodeId, array $data = [], ?string $reason = null): void
    {
        DocumentWorkflowEvent::create([
            'workflow_id' => $run->workflow->id,
            'type' => $type,
            'node_id' => $nodeId,
            'user_id' => $run->user?->id,
            'reason' => $reason,
            'data' => $data,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    private function transaction(callable $fn): mixed
    {
        $this->tenants->require();

        return DB::connection(TenantContext::CONNECTION)->transaction($fn);
    }
}
