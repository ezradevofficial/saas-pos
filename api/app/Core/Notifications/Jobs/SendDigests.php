<?php

namespace App\Core\Notifications\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Notifications\Digest\Digests;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * NOT-05: queue the digests due in one tenant, in that tenant's context
 * (TenantAware). Dispatched hourly for every tenant by
 * `notifications:send-digests`.
 */
class SendDigests implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public string $tenantId,
        public string $at,
    ) {
        $this->onQueue(config('notifications.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(Digests $digests, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $digests->sendDue(CarbonImmutable::parse($this->at));
    }
}
