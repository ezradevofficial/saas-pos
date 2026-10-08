<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalAssignment;
use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\Runtime\WorkflowBlocked;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * APR-05, in the current tenant: reminders and escalation of pending
 * requests whose timer fell due by $at (ApprovalClock sets the timers in
 * business time).
 *
 * - a reminder goes to the step's pending approvers; when several offsets
 *   passed since the last run, one reminder covers them;
 * - escalation adds the next eligible approver (escalation `to`:
 *   next_level, role or user; never the requester) as an escalated
 *   approver whose decision alone decides the step, notifies them, and
 *   restarts the timers; with nobody further to escalate to, the final
 *   timeout decides the request automatically per `final`
 *   (approve/reject; the system acts), or the timers stop.
 *
 * Each request is processed in its own transaction with its row locked and
 * its timers moved on before commit, so a rerun (or an overlapping run)
 * never reminds or escalates twice. An automatic decision the flow
 * refuses (an exit rule) is logged and not retried.
 */
class ApprovalTimers
{
    public function __construct(
        private readonly ApprovalDecisions $decisions,
        private readonly ApprovalRouting $routing,
        private readonly ApprovalClock $clock,
        private readonly ApprovalLog $log,
        private readonly ApprovalNotices $notices,
    ) {}

    /** @return array{reminded: int, escalated: int, decided: int, stopped: int} */
    public function run(CarbonImmutable $at): array
    {
        $counts = ['reminded' => 0, 'escalated' => 0, 'decided' => 0, 'stopped' => 0];
        $due = ApprovalRequest::query()->where('status', ApprovalRequest::PENDING)
            ->where(fn ($q) => $q->where('next_reminder_at', '<=', $at)->orWhere('escalate_at', '<=', $at))
            ->orderBy('received_at')->pluck('id');

        foreach ($due as $id) {
            try {
                $result = DB::connection(TenantContext::CONNECTION)->transaction(fn () => $this->process((string) $id, $at));
            } catch (ApiException $e) {
                $this->failed((string) $id, $e);

                continue;
            }

            foreach ($result as $key => $n) {
                $counts[$key] += $n;
            }
        }

        return $counts;
    }

    /** @return array{reminded: int, escalated: int, decided: int, stopped: int} */
    private function process(string $id, CarbonImmutable $at): array
    {
        $counts = ['reminded' => 0, 'escalated' => 0, 'decided' => 0, 'stopped' => 0];
        $request = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();

        if ($request === null || ! $request->isPending()) {
            return $counts;
        }

        if ($request->escalate_at !== null && $request->escalate_at->lessThanOrEqualTo($at)) {
            $counts[$this->escalate($request, $at)]++;

            return $counts;
        }

        if ($request->next_reminder_at !== null && $request->next_reminder_at->lessThanOrEqualTo($at)) {
            $this->remind($request, $at);
            $counts['reminded']++;
        }

        return $counts;
    }

    private function remind(ApprovalRequest $request, CarbonImmutable $at): void
    {
        $request->reminders_sent = max($request->reminders_sent + 1, $this->clock->remindersDue($request, $at));
        $next = $request->config['reminders'][$request->reminders_sent] ?? null;
        $request->next_reminder_at = $next === null ? null : $this->clock->after($request, $request->level_started_at, $next);
        $request->save();

        $users = $this->pendingApprovers($request);
        $this->log->action($request, 'reminded', null, null, ['users' => $users, 'reminder' => $request->reminders_sent]);
        $this->notices->requested($request, $users, ApprovalNotices::REMINDER, [
            'due' => $request->due_at === null ? '' : $request->due_at->setTimezone($this->clock->timezone($request->company_id))->format('Y-m-d H:i'),
        ]);
    }

    /** @return 'escalated'|'decided'|'stopped' */
    private function escalate(ApprovalRequest $request, CarbonImmutable $at): string
    {
        $config = $request->config;
        $users = ($config['escalation']['after'] ?? null) === null ? [] : $this->routing->escalationTargets($request, $this->routing->subject($request));

        if ($users !== []) {
            $waitingFor = $this->pendingApprovers($request);
            $this->routing->assign($request, $users, ApprovalAssignment::SOURCE_ESCALATED);
            $request->forceFill([
                'escalation_level' => $request->escalation_level + 1,
                'level_started_at' => $at,
                'reminders_sent' => 0,
                'blocked_reason' => null,
            ]);
            $this->clock->schedule($request);
            $request->save();

            $this->log->action($request, 'escalated', null, null, ['users' => $users, 'level' => $request->escalation_level]);
            $this->log->audit('escalate', $request, null, ['approvers' => $users, 'level' => $request->escalation_level]);
            $this->notices->requested($request, $users, ApprovalNotices::ESCALATED, [
                'waiting_for' => implode(', ', User::query()->whereKey($waitingFor)->orderBy('name')->pluck('name')->all()),
            ]);

            return 'escalated';
        }

        $final = $config['escalation']['final'] ?? null;

        if ($final === null) {
            $request->forceFill(['escalate_at' => null])->save();
            $this->log->action($request, 'escalation_exhausted', null);

            return 'stopped';
        }

        $this->decisions->decideAutomatically($request, $final);

        return 'decided';
    }

    /** @return list<string> */
    private function pendingApprovers(ApprovalRequest $request): array
    {
        return $request->assignments()->where('step', $request->step)->where('status', ApprovalAssignment::PENDING)->pluck('user_id')->all();
    }

    /** The automatic decision was refused by the flow: stop the timers and say why in the history. */
    private function failed(string $id, ApiException $e): void
    {
        Log::warning('Approval timer action refused', ['approval_id' => $id, 'code' => $e->errorCode]);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($id, $e) {
            $request = ApprovalRequest::query()->whereKey($id)->lockForUpdate()->first();

            if ($request === null || ! $request->isPending()) {
                return;
            }

            $request->forceFill(['escalate_at' => null])->save();
            $this->log->action($request, 'auto_failed', null, null, ['code' => $e->errorCode, 'reasons' => $e instanceof WorkflowBlocked ? $e->reasons : []]);
        });
    }
}
