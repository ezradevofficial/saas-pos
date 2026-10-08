<?php

namespace App\Core\Workflow\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Workflow\Runtime\StageTimers;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * WF-09: stage reminders, overdue notices and escalations due in one
 * tenant, in that tenant's context (TenantAware). Unique per tenant while
 * queued or running, so overlapping runs never act twice; StageTimers also
 * locks each position and records what it sent before committing.
 */
class ProcessStageTimers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 600;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(StageTimers $timers, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $timers->run(CarbonImmutable::parse($this->at));
    }
}
