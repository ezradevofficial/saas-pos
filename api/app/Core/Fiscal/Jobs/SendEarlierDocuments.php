<?php

namespace App\Core\Fiscal\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Fiscal\Contracts\ListsFiscalDocuments;
use App\Core\Fiscal\FiscalQueue;
use App\Core\Fiscal\FiscalSources;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * "Send earlier sales": queues every document of a company issued since a
 * date, from each source that can list them (ListsFiscalDocuments). The
 * queue keeps one submission per document, so documents already queued
 * are left as they are and running it twice changes nothing.
 */
class SendEarlierDocuments implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 60;

    public function __construct(
        public string $tenantId,
        public string $companyId,
        public string $from,
    ) {
        $this->onQueue(config('fiscal.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(FiscalSources $sources, FiscalQueue $queue, AuditContext $audit): void
    {
        $audit->reset();
        $from = CarbonImmutable::parse($this->from);

        foreach ($sources->all() as $key => $source) {
            if (! $source instanceof ListsFiscalDocuments) {
                continue;
            }

            foreach ($source->documentsSince($this->companyId, $from) as [$type, $id]) {
                if ($queue->enqueue($key, $type, $id, $this->companyId) === null) {
                    return; // Transmission was switched off meanwhile.
                }
            }
        }
    }
}
