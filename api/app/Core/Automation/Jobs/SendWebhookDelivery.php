<?php

namespace App\Core\Automation\Jobs;

use App\Core\Automation\Runtime\WebhookDeliveries;
use App\Core\Tenancy\Jobs\TenantAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** AUTO-03: send one outbox webhook delivery in its tenant's context (WebhookDeliveries). */
class SendWebhookDelivery implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Retries are new jobs dispatched by WebhookDeliveries; a crash is reaped. */
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
    ) {
        $this->onQueue(config('automation.queue'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(WebhookDeliveries $deliveries): void
    {
        $deliveries->deliver($this->deliveryId);
    }
}
