<?php

namespace App\Core\Webhooks\Delivery;

use App\Core\Rbac\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Jobs\FanOutWebhookEvent;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * HOOK-01: listens to every catalogued internal event (after commit: they
 * are all ShouldDispatchAfterCommit). For each public type of the event it
 * captures the occurrence (no queries), and queues one fan-out job only
 * when the type's module is active and the tenant has a live subscription
 * to it, both answered from per-tenant memos (ModuleRegistry,
 * WebhookSubscriptions), so a POS upload pays no query per sale and a
 * tenant without subscriptions queues nothing.
 */
class WebhookDispatcher
{
    public function __construct(
        private readonly WebhookCatalogue $catalogue,
        private readonly WebhookSubscriptions $subscriptions,
        private readonly ModuleRegistry $modules,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(object $event): void
    {
        // A webhook must never fail the request that raised the event (a till's upload, NFR-04).
        try {
            $this->dispatch($event);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function dispatch(object $event): void
    {
        $tenantId = $event->tenantId ?? null;

        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }

        foreach ($this->catalogue->forEvent($event::class) as $type) {
            $occurrence = ($type->capture)($event);

            if ($occurrence === null) {
                continue;
            }

            $wanted = fn (): bool => $this->modules->isActive($type->module) && $this->subscriptions->wants($type->key);

            // The raising request or job is usually in the event's tenant already.
            if (! ($this->tenants->id() === $tenantId ? $wanted() : $this->tenants->run($tenantId, $wanted))) {
                continue;
            }

            FanOutWebhookEvent::dispatch($tenantId, $type->key, (string) Str::uuid7(), CarbonImmutable::now()->toIso8601ZuluString(), $occurrence->toArray());
        }
    }
}
