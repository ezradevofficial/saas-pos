<?php

namespace App\Core\Approvals;

use App\Core\Approvals\Models\ApprovalRequest;
use App\Core\Workflow\Calendar\BusinessCalendar;
use Carbon\CarbonImmutable;

/**
 * APR-05 timers of a pending request, in the company's business time
 * (working hours and public holidays, BusinessCalendar):
 *
 * - reminders: each configured offset, counted from when the current
 *   approvers received the request (`level_started_at`, reset when the
 *   chain moves on or the request escalates);
 * - escalation: `escalation.after` from the same moment; without it, a
 *   `final` decision falls due at the node's time limit (`due_at`).
 */
class ApprovalClock
{
    public function __construct(private readonly BusinessCalendar $calendar) {}

    /** Set `next_reminder_at` and `escalate_at` for the current level (not saved). */
    public function schedule(ApprovalRequest $request): void
    {
        $config = $request->config;
        $from = $request->level_started_at ?? CarbonImmutable::now();
        $reminders = $config['reminders'] ?? [];
        $next = $reminders[(int) $request->reminders_sent] ?? null;

        $request->next_reminder_at = $next === null ? null : $this->after($request, $from, $next);

        $after = $config['escalation']['after'] ?? null;
        $request->escalate_at = match (true) {
            $after !== null => $this->after($request, $from, $after),
            ($config['escalation']['final'] ?? null) !== null => $request->due_at,
            default => null,
        };
    }

    /** The reminder offsets elapsed by $at (how many reminders are due in all). */
    public function remindersDue(ApprovalRequest $request, CarbonImmutable $at): int
    {
        $from = $request->level_started_at;
        $count = 0;

        foreach ($request->config['reminders'] ?? [] as $offset) {
            if ($this->after($request, $from, $offset)->lessThanOrEqualTo($at)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param array{amount: int, unit: string} $duration */
    public function after(ApprovalRequest $request, CarbonImmutable $from, array $duration): CarbonImmutable
    {
        return $this->calendar->due($from, (int) $duration['amount'], (string) $duration['unit'], $this->calendar->forCompany($request->company_id));
    }

    /** The request's company's time zone. */
    public function timezone(?string $companyId): string
    {
        return $this->calendar->forCompany($companyId)->timezone;
    }

    /** Today's date (Y-m-d) in the request's company's time zone (delegation ranges, APR-06). */
    public function today(?string $companyId): string
    {
        return CarbonImmutable::now($this->timezone($companyId))->toDateString();
    }
}
