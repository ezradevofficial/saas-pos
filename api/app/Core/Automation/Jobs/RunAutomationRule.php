<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\Runtime\RuleRunner;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * AUTO-05: run one logged automation run, in its tenant's context
 * (TenantAware: row-level security keeps it inside that tenant). Retries
 * are new jobs dispatched by RuleRunner with backoff; a worker crash
 * leaves the run `running` with its attempts counted.
 */
class RunAutomationRule implements ShouldQueue
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
        $runner->execute($this->runId);
    }
}
