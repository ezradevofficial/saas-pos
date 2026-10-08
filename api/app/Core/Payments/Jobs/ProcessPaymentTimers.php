<?php

namespace App\Core\Payments\Jobs;

use App\Core\Audit\AuditContext;
use App\Core\Payments\PaymentIntents;
use App\Core\Tenancy\Jobs\TenantAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * STK pushes past their timeout, payouts without a result and manual
 * codes due for a check, in one tenant's context (TenantAware). Unique per
 * tenant while queued or running; each intent is locked when changed and
 * a final status never changes again (PaymentIntents::apply).
 */
class ProcessPaymentTimers implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 300;

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

    public function handle(PaymentIntents $intents, AuditContext $audit): void
    {
        // The system acts: no user or device is recorded (AUD-02).
        $audit->reset();

        $intents->processTimers(CarbonImmutable::parse($this->at));
    }
}
