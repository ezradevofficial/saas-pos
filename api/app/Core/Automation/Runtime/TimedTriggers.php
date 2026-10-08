<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Capabilities\FindsDocumentsByDate;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\AutomationRun;
use App\Core\Automation\Triggers\TriggerHit;
use App\Core\Automation\Triggers\Triggers;
use App\Core\Tenancy\Models\Company;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Carbon\CarbonImmutable;

/**
 * AUTO-01 triggers that come from the clock, in the current tenant:
 *
 * - schedules: every live schedule rule whose next occurrence has come
 *   runs once for it (dedupe key `schedule:<occurrence>`); the next
 *   occurrence is computed from now, so occurrences missed while the
 *   system was down run once, not once each;
 * - dates: once a day per company, at the first scan after
 *   `automation.date_scan_hour` in the company's time zone, each live
 *   date rule asks its type for the documents whose date falls on the
 *   target day; each runs once (dedupe key `date:<document>:<target day>`).
 *   Every scan also looks at yesterday, so a day the scans missed is
 *   caught up. A throttled schedule occurrence is not moved on.
 */
class TimedTriggers
{
    public function __construct(
        private readonly RuleRunner $runner,
        private readonly Rules $rules,
        private readonly DocumentTypeRegistry $types,
    ) {}

    /** @return int runs queued */
    public function schedules(CarbonImmutable $at): int
    {
        $queued = 0;
        $due = AutomationRule::query()->live()->where('trigger_type', Triggers::SCHEDULE)
            ->whereNotNull('next_run_at')->where('next_run_at', '<=', $at)
            ->orderBy('next_run_at')->lockForUpdate()->get();

        foreach ($due as $rule) {
            $occurrence = CarbonImmutable::instance($rule->next_run_at)->utc();
            $hit = new TriggerHit(Triggers::SCHEDULE, ['occurrence' => $occurrence->toIso8601ZuluString()], null, 'schedule:'.$occurrence->toIso8601ZuluString(), $rule->company_id);

            $run = $this->types->find($rule->document_type) === null ? null : $this->runner->dispatch($rule, $hit, null);

            // A throttled occurrence is tried again at the next scan (next minute).
            if ($run?->outcome === AutomationRun::THROTTLED) {
                continue;
            }

            if ($run !== null) {
                $queued++;
            }

            $rule->next_run_at = $this->rules->nextRunAt($rule, $at);
            $rule->saveQuietly();
        }

        return $queued;
    }

    /** @return int runs queued */
    public function dates(CarbonImmutable $at): int
    {
        $queued = 0;
        $rules = AutomationRule::query()->live()->where('trigger_type', Triggers::DATE)->orderBy('created_at')->get();

        if ($rules->isEmpty()) {
            return 0;
        }

        $companies = Company::query()->whereNull('archived_at')->get(['id', 'timezone']);
        $hour = (int) config('automation.date_scan_hour', 6);

        foreach ($rules as $rule) {
            $type = $this->types->find($rule->document_type);

            if (! $type instanceof FindsDocumentsByDate) {
                continue;
            }

            foreach ($companies as $company) {
                if ($rule->company_id !== null && $rule->company_id !== $company->id) {
                    continue;
                }

                $timezone = $company->timezone ?: 'UTC';
                $local = $at->setTimezone($timezone);

                // Today's scan once its hour has come; yesterday's again, to catch
                // up a day the scans missed (an occurrence runs once either way),
                // for rules that already existed at yesterday's scan hour.
                $days = [];
                $yesterday = $local->subDay()->startOfDay()->setTime($hour, 0);

                if ($rule->created_at !== null && CarbonImmutable::instance($rule->created_at)->lessThanOrEqualTo($yesterday)) {
                    $days[] = $yesterday->toDateString();
                }

                if ($local->hour >= $hour) {
                    $days[] = $local->toDateString();
                }

                foreach ($days as $day) {
                    $target = Triggers::targetDate($rule->trigger, $day);

                    foreach ($type->documentsOnDate((string) $rule->trigger['field'], $target, $company->id, $timezone) as $documentId) {
                        $hit = new TriggerHit(Triggers::DATE, ['field' => $rule->trigger['field'], 'date' => $target], $documentId, "date:{$documentId}:{$target}", $company->id);

                        if ($this->runner->dispatch($rule, $hit, null) !== null) {
                            $queued++;
                        }
                    }
                }
            }
        }

        return $queued;
    }
}
