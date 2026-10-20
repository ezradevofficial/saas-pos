<?php

namespace App\Core\Webhooks\Jobs;

use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Webhooks\Delivery\WebhookDeliverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * HOOK-01: one attempt at a webhook delivery, in its tenant (WebhookDeliverer).
 * At most one is queued per delivery (unique until it starts), so the sweep
 * never queues a second copy of one still waiting; the lock outlasts the
 * longest back-off.
 */
class SendWebhook implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, Queueable;

    public int $uniqueFor = 90000;

    /** Retries are new jobs dispatched with the back-off; a lost job is picked up by webhooks:sweep. */
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
    ) {
        $this->onQueue(config('webhooks.queue', 'webhooks'));
    }

    public function uniqueId(): string
    {
        return $this->deliveryId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(WebhookDeliverer $deliverer): void
    {
        $deliverer->deliver($this->deliveryId);
    }
}
