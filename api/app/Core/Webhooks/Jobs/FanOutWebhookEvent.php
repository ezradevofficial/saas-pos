<?php

namespace App\Core\Webhooks\Jobs;

use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Webhooks\Delivery\WebhookFanOut;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/** HOOK-01: one captured event turned into deliveries, in its tenant (WebhookFanOut), on the webhooks queue. */
class FanOutWebhookEvent implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** @param  array<string, mixed>  $occurrence  WebhookOccurrence::toArray() */
    public function __construct(
        public string $tenantId,
        public string $type,
        public string $eventId,
        public string $occurredAt,
        public array $occurrence,
    ) {
        $this->onQueue(config('webhooks.queue', 'webhooks'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(WebhookFanOut $fanOut): void
    {
        $fanOut->fanOut($this->type, $this->eventId, $this->occurredAt, $this->occurrence);
    }
}
