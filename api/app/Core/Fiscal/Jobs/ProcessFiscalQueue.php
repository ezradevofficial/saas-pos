<?php

namespace App\Core\Fiscal\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Send one tenant's due fiscal submissions, in its context (TenantAware),
 * on the `fiscal` queue. Unique per tenant while queued; each submission
 * is also claimed under a row lock (FiscalQueue), so overlapping runs
 * never send one twice.
 */
class ProcessFiscalQueue implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 60;

    public int $timeout = 60;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {
        $this->onQueue(config('fiscal.queue'));
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

    public function handle(FiscalQueue $queue, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        // A run queued earlier still sends what became due since.
        $queue->process(CarbonImmutable::parse($this->at)->max(CarbonImmutable::now()));
    }
}
