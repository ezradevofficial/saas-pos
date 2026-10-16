<?php

namespace App\Core\Webhooks\Jobs;

use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Webhooks\Delivery\WebhookDeliverer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** HOOK-01: one attempt at a webhook delivery, in its tenant (WebhookDeliverer). */
class SendWebhook implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** Retries are new jobs dispatched with the back-off; a lost job is picked up by webhooks:sweep. */
    public int $tries = 1;

    public function __construct(
        public string $tenantId,
        public string $deliveryId,
    ) {
        $this->onQueue(config('webhooks.queue', 'webhooks'));
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
