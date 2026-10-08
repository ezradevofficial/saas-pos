<?php

namespace App\Core\Workflow\Runtime;

use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\Role;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Calendar\BusinessCalendar;
use App\Core\Workflow\Definitions\GraphValidator;
use App\Core\Workflow\Definitions\RoleRefs;
use App\Core\Workflow\DocumentTypes\DocumentScope;
use App\Core\Workflow\DocumentTypes\DocumentType;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use App\Core\Workflow\Listeners\SendWorkflowNotification;
use App\Core\Workflow\Models\DocumentWorkflow;
use App\Core\Workflow\Models\DocumentWorkflowEvent;
use App\Core\Workflow\Models\DocumentWorkflowToken;
use App\Core\Workflow\Models\WorkflowVersion;
use App\Core\Workflow\WorkflowAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WF-09 for plain stages (approvals have their own timers, APR-05), in
 * the current tenant and the company's business time (BusinessCalendar):
 *
 *   "reminders":  [{amount, unit}, ...]  offsets from entering the stage;
 *   "due":        {amount, unit}         the time limit (the token's due_at);
 *   "escalation": {after?: {amount, unit}, to: {type: role, role} | {type: user, user_id}}
 *
 * - each reminder offset sends `core.workflow.stage_reminder` to the
 *   stage's holders (StagePermissions::holders: its exit roles, else the
 *   type's act permission) who may see the document; several offsets
 *   passed since the last run make one reminder;
 * - the due time sends `core.workflow.stage_overdue` to the same people;
 * - escalation (at `after`, else at the due time) sends
 *   `core.workflow.stage_overdue` to the `to` role's holders at the
 *   document's place, or the `to` user, who may see the document.
 *   A stage is completed by people only: escalation never decides or
 *   moves it, it only tells someone else (no `final`, no `next_level`).
 *
 * Each position is processed in its own transaction with its row locked
 * and what was sent recorded before commit (`reminders_sent`,
 * `overdue_notified_at`, `escalated_at`, `next_timer_at`), so a rerun or an
 * overlapping run never sends a timer twice. The flow's history records
 * each notice (`reminded`, `overdue`, `escalated`).
 */
class StageTimers
{
    public const REMINDER = 'core.workflow.stage_reminder';

    public const OVERDUE = 'core.workflow.stage_overdue';

    /** Most people one notice goes to. */
    private const MAX_RECIPIENTS = 200;

    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly StagePermissions $permissions,
        private readonly WorkflowAccess $access,
        private readonly RoleRefs $roles,
        private readonly Notifier $notifier,
        private readonly DocumentTypeRegistry $types,
    ) {}

    /**
     * When a new position at a plain stage first needs attention (null:
     * never). The engine sets it when the document arrives.
     */
    public function first(array $node, CarbonImmutable $enteredAt, ?CarbonImmutable $dueAt, ?string $companyId): ?CarbonImmutable
    {
        if (($node['type'] ?? null) !== 'stage') {
            return null;
        }

        return $this->next($this->plan($node, $enteredAt, $dueAt, $companyId), 0, false, false);
    }

    /** @return array{reminded: int, overdue: int, escalated: int} */
    public function run(CarbonImmutable $at): array
    {
        $counts = ['reminded' => 0, 'overdue' => 0, 'escalated' => 0];
        // Positions of inactive modules wait until the module is active again (RBAC-08).
        $due = DocumentWorkflowToken::query()
            ->join('document_workflows', 'document_workflows.id', '=', 'document_workflow_tokens.workflow_id')
            ->where('document_workflow_tokens.status', DocumentWorkflowToken::ACTIVE)
            ->where('document_workflow_tokens.next_timer_at', '<=', $at)
            ->where('document_workflows.status', DocumentWorkflow::RUNNING)
            ->whereIn('document_workflows.document_type', $this->types->keys())
            ->orderBy('document_workflow_tokens.next_timer_at')
            ->pluck('document_workflow_tokens.id');

        foreach ($due as $id) {
            try {
                $result = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->process((string) $id, $at));
            } catch (Throwable $e) {
                // One position that cannot be processed never stops the tenant's run.
                Log::warning('Stage timer failed', ['token_id' => $id, 'error' => $e::class.': '.$e->getMessage()]);

                continue;
            }

            foreach ($result as $key => $n) {
                $counts[$key] += $n;
            }
        }

        return $counts;
    }

    /** @return array{reminded: int, overdue: int, escalated: int} */
    private function process(string $id, CarbonImmutable $at): array
    {
        $counts = ['reminded' => 0, 'overdue' => 0, 'escalated' => 0];
        $token = DocumentWorkflowToken::query()->whereKey($id)->lockForUpdate()->first();

        if ($token === null || $token->status !== DocumentWorkflowToken::ACTIVE || $token->next_timer_at === null) {
            return $counts;
        }

        $workflow = DocumentWorkflow::query()->find($token->workflow_id);
        $type = $workflow === null ? null : $this->types->find($workflow->document_type);
        $scope = $type?->scope($workflow->document_id);
        $flow = $workflow === null ? null : WorkflowVersion::query()->find($workflow->version_id)?->flow();
        $node = $flow?->node($token->node_id);

        if ($workflow === null || ! $workflow->isRunning() || $type === null || $scope === null || ($node['type'] ?? null) !== 'stage') {
            $token->forceFill(['next_timer_at' => null])->save();

            return $counts;
        }

        $plan = $this->plan($node, CarbonImmutable::instance($token->entered_at), $token->due_at === null ? null : CarbonImmutable::instance($token->due_at), $scope->companyId);
        $remindersDue = count(array_filter($plan['reminders'], fn (CarbonImmutable $time) => $time->lessThanOrEqualTo($at)));
        $remind = $remindersDue > (int) $token->reminders_sent;
        $overdue = $token->overdue_notified_at === null && $plan['due'] !== null && $plan['due']->lessThanOrEqualTo($at);
        $escalate = $token->escalated_at === null && $plan['escalate'] !== null && $plan['escalate']->lessThanOrEqualTo($at);

        $token->forceFill([
            'reminders_sent' => max((int) $token->reminders_sent, $remindersDue),
            'overdue_notified_at' => $overdue ? $at : $token->overdue_notified_at,
            'escalated_at' => $escalate ? $at : $token->escalated_at,
        ]);
        $token->next_timer_at = $this->next($plan, (int) $token->reminders_sent, $token->overdue_notified_at !== null, $token->escalated_at !== null);
        $token->save();

        $name = $flow->name($token->node_id);
        $data = [
            'document_type' => __($type->label()),
            'document_number' => (string) ($type->summary($workflow->document_id)['number'] ?? ''),
            'step' => $name,
            'due' => $token->due_at === null ? '' : CarbonImmutable::instance($token->due_at)->setTimezone($this->calendar->forCompany($scope->companyId)->timezone)->format('Y-m-d H:i'),
        ];
        $link = SendWorkflowNotification::statusLink($workflow->document_type, $workflow->document_id);

        if ($remind || $overdue) {
            $holders = $this->visible($this->holders($node, $scope, $type), $type, $scope);

            if ($remind) {
                $this->notify(self::REMINDER, $holders, $data, $link);
                $this->event($workflow, $token, 'reminded', ['users' => $holders, 'reminder' => (int) $token->reminders_sent]);
                $counts['reminded']++;
            }

            if ($overdue) {
                $this->notify(self::OVERDUE, $holders, $data, $link);
                $this->event($workflow, $token, 'overdue', ['users' => $holders]);
                $counts['overdue']++;
            }
        }

        if ($escalate) {
            $targets = $this->visible($this->escalationTargets($node, $scope, $type), $type, $scope);
            $this->notify(self::OVERDUE, $targets, $data, $link);
            $this->event($workflow, $token, 'escalated', ['users' => $targets]);
            $counts['escalated']++;
        }

        return $counts;
    }

    /**
     * @return array{reminders: list<CarbonImmutable>, due: ?CarbonImmutable, escalate: ?CarbonImmutable}
     */
    private function plan(array $node, CarbonImmutable $enteredAt, ?CarbonImmutable $dueAt, ?string $companyId): array
    {
        $spec = null;
        $after = function (array $duration) use ($enteredAt, $companyId, &$spec): CarbonImmutable {
            $spec ??= $this->calendar->forCompany($companyId);

            return $this->calendar->due($enteredAt, $duration['amount'], $duration['unit'], $spec);
        };

        $reminders = [];

        foreach (is_array($node['reminders'] ?? null) && array_is_list($node['reminders']) ? $node['reminders'] : [] as $offset) {
            if (($duration = self::duration($offset)) !== null) {
                $reminders[] = $after($duration);
            }
        }

        usort($reminders, fn (CarbonImmutable $a, CarbonImmutable $b) => $a <=> $b);

        $escalation = is_array($node['escalation'] ?? null) ? $node['escalation'] : [];
        $escalate = null;

        if (self::escalationTo($escalation) !== null) {
            $duration = self::duration($escalation['after'] ?? null);
            $escalate = $duration === null ? $dueAt : $after($duration);
        }

        return ['reminders' => $reminders, 'due' => $dueAt, 'escalate' => $escalate];
    }

    /** The earliest timer not yet sent. */
    private function next(array $plan, int $remindersSent, bool $overdueSent, bool $escalated): ?CarbonImmutable
    {
        $times = array_slice($plan['reminders'], $remindersSent);

        if (! $overdueSent && $plan['due'] !== null) {
            $times[] = $plan['due'];
        }

        if (! $escalated && $plan['escalate'] !== null) {
            $times[] = $plan['escalate'];
        }

        return $times === [] ? null : min($times);
    }

    /** @return list<string> user ids holding the stage at the document's place */
    private function holders(array $node, DocumentScope $scope, DocumentType $type): array
    {
        $roleIds = $this->permissions->roleIds($node, 'exit') ?? $this->rolesWith($type->actPermission());

        return array_column($this->permissions->holders($node, $scope, $type, $roleIds, null, self::MAX_RECIPIENTS)['users'], 'id');
    }

    /** @return list<string> */
    private function escalationTargets(array $node, DocumentScope $scope, DocumentType $type): array
    {
        $to = self::escalationTo(is_array($node['escalation'] ?? null) ? $node['escalation'] : []);

        return match ($to['type'] ?? null) {
            'role' => array_column($this->permissions->holders($node, $scope, $type, $this->roles->resolve([$to['role']]), null, self::MAX_RECIPIENTS)['users'], 'id'),
            'user' => User::query()->whereKey($to['user_id'])->where('status', User::STATUS_ACTIVE)->pluck('id')->map(fn ($id) => (string) $id)->all(),
            default => [],
        };
    }

    /**
     * Only people who may see the document hear about it (RBAC-04).
     *
     * @param  list<string>  $userIds
     * @return list<string>
     */
    private function visible(array $userIds, DocumentType $type, DocumentScope $scope): array
    {
        return User::query()->whereKey($userIds)->orderBy('id')->get()
            ->filter(fn (User $user) => $this->access->seesDocument($user, $type, $scope))
            ->map(fn (User $user) => (string) $user->id)
            ->values()->all();
    }

    /** @return list<string> active roles carrying $permission */
    private function rolesWith(string $permission): array
    {
        return Role::query()->whereNull('archived_at')
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    /** @param list<string> $userIds */
    private function notify(string $event, array $userIds, array $data, string $link): void
    {
        if ($userIds !== []) {
            $this->notifier->send(new NotificationEvent($event, $userIds, $data, $link));
        }
    }

    private function event(DocumentWorkflow $workflow, DocumentWorkflowToken $token, string $type, array $data): void
    {
        DocumentWorkflowEvent::create([
            'workflow_id' => $workflow->id,
            'type' => $type,
            'node_id' => $token->node_id,
            'data' => $data,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }

    /** @return array{type: 'role', role: string}|array{type: 'user', user_id: string}|null */
    public static function escalationTo(array $escalation): ?array
    {
        $to = $escalation['to'] ?? null;

        return match (true) {
            is_array($to) && ($to['type'] ?? null) === 'role' && is_string($to['role'] ?? null) => ['type' => 'role', 'role' => $to['role']],
            is_array($to) && ($to['type'] ?? null) === 'user' && is_string($to['user_id'] ?? null) => ['type' => 'user', 'user_id' => $to['user_id']],
            default => null,
        };
    }

    /** @return array{amount: int, unit: string}|null */
    public static function duration(mixed $value): ?array
    {
        if (! is_array($value) || ! is_int($value['amount'] ?? null) || $value['amount'] < 1 || $value['amount'] > 10000
            || ! in_array($value['unit'] ?? null, GraphValidator::DUE_UNITS, true)) {
            return null;
        }

        return ['amount' => $value['amount'], 'unit' => $value['unit']];
    }
}
