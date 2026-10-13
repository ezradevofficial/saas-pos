<?php

namespace App\Core\Billing\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Billing\Dunning\BillingCycle;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** SUB-03, SUB-05: one tenant's billing clock (BillingCycle), in its context; unique per tenant while queued. */
class RunBillingCycle implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {
        $this->onQueue(config('payments.queue'));
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

    public function handle(BillingCycle $cycle, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();
        $cycle->run(CarbonImmutable::parse($this->at));
    }
}
