<?php

namespace App\Core\Approvals\Jobs;

use App\Core\Approvals\ApprovalTimers;
use App\Core\Audit\AuditContext;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * APR-05: reminders, escalations and final timeouts due in one tenant, in
 * that tenant's context (TenantAware). Unique per tenant while queued or
 * running, so overlapping runs never act twice; ApprovalTimers also locks
 * each request and moves its timers on before committing.
 */
class ProcessApprovalTimers implements ShouldBeUnique, ShouldQueue
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

    public function handle(ApprovalTimers $timers, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $timers->run(CarbonImmutable::parse($this->at));
    }
}
