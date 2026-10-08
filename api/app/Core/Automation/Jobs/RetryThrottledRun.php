<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\Runtime\RuleRunner;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * AUTO-06 (L3): the one retry of a throttled live run, dispatched with a
 * delay of the throttle window, in its tenant's context.
 */
class RetryThrottledRun implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $runId,
    ) {
        $this->onQueue(config('automation.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(RuleRunner $runner): void
    {
        $runner->retryThrottled($this->runId);
    }
}
