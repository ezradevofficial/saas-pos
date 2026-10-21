<?php

namespace App\Core\Marketplace\Jobs;

use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Tenancy\Jobs\TenantAware;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * MKT-02: the developer rolled the app's webhook signing secret; the
 * install's subscription in this tenant signs with it from now on, and
 * with the previous one too until the overlap ends (two signatures).
 */
class RollAppWebhookSecret implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public function __construct(
        public string $tenantId,
        public string $appId,
        public string $previousUntil,
    ) {
        $this->onQueue(config('webhooks.queue', 'webhooks'));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new TenantAware];
    }

    public function handle(WebhookSubscriptions $subscriptions): void
    {
        $installation = AppInstallation::query()->where('app_id', $this->appId)->where('status', AppInstallation::ACTIVE)->first();
        $subscription = $installation?->webhook_subscription_id === null ? null : WebhookSubscription::query()->find($installation->webhook_subscription_id);
        $app = MarketplaceApp::query()->find($this->appId);

        if ($subscription === null || $app === null || $subscription->secret === $app->webhook_secret) {
            return;
        }

        $subscriptions->rollSecret($subscription, (string) $app->webhook_secret, CarbonImmutable::parse($this->previousUntil));
    }
}
