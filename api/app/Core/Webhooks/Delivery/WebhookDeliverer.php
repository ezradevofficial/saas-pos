<?php

namespace App\Core\Webhooks\Delivery;

use App\Core\Audit\Auditor;
use App\Core\Automation\Webhooks\WebhookRefused;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Automation\Webhooks\WebhookUnreachable;
use App\Core\Identity\Models\User;
use App\Core\Notifications\NotificationEvent;
use App\Core\Notifications\Notifier;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Jobs\SendWebhook;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HOOK-01: one attempt at a delivery (the SendWebhook job).
 *
 * - The delivery is claimed (pending or retrying to sending, attempts + 1),
 *   so two workers never send it twice.
 * - It is sent only while its subscription is live and its principal
 *   active; otherwise it fails at once (`disabled`, `principal`).
 * - The URL is checked again and resolved now (WebhookEndpoints): a
 *   refused address fails at once (`refused.<reason>`), with no request.
 * - The body is signed per WebhookSignature with every signing secret
 *   (two during a rotation's overlap); `Webhook-Id` is the event id, the
 *   same on every attempt and replay.
 * - A 2xx answer delivers it and resets the subscription's failure count.
 *   No answer, or any other status (redirects are never followed), is tried
 *   again after the next `webhooks.backoff` wait; after the last one the
 *   delivery is failed (the dead-letter state, replayable from the log).
 * - A delivery failed for good counts against its subscription; at
 *   `webhooks.disable_after` in a row the subscription is switched off and
 *   its managers (and its user principal) are notified
 *   (`core.webhook.disabled`). Test events are tried once and never count.
 */
class WebhookDeliverer
{
    public const DISABLED_EVENT = 'core.webhook.disabled';

    public function __construct(
        private readonly WebhookEndpoints $endpoints,
        private readonly WebhookSender $sender,
        private readonly WebhookPrincipals $principals,
        private readonly WebhookSubscriptions $subscriptions,
        private readonly Notifier $notifier,
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    public function deliver(string $deliveryId): void
    {
        $now = CarbonImmutable::now();
        $claimed = WebhookDelivery::query()->whereKey($deliveryId)
            ->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::RETRYING])
            ->update(['status' => WebhookDelivery::SENDING, 'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => null, 'last_attempt_at' => $now, 'updated_at' => $now]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = WebhookDelivery::query()->with('subscription')->findOrFail($deliveryId);
        $subscription = $delivery->subscription;

        if (! $subscription->isLive()) {
            $this->fail($delivery, 'disabled', count: false);

            return;
        }

        $principal = $this->principals->of($subscription);

        if ($principal === null || ! $principal->isActive()) {
            $this->fail($delivery, 'principal', count: false);

            return;
        }

        try {
            $target = $this->endpoints->target($subscription->url);
        } catch (WebhookRefused $e) {
            $this->fail($delivery, 'refused.'.$e->reason);

            return;
        }

        $body = (string) json_encode(self::body($delivery->payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = $now->getTimestamp();
        $started = hrtime(true);

        try {
            $response = $this->sender->post($target, $body, [
                'User-Agent' => 'platform-webhooks/1',
                WebhookSignature::ID_HEADER => $delivery->event_id,
                WebhookSignature::TIMESTAMP_HEADER => (string) $timestamp,
                WebhookSignature::SIGNATURE_HEADER => WebhookSignature::header($subscription->signingSecrets($now), $delivery->event_id, $timestamp, $body),
            ], (int) config('webhooks.timeout', 10), (int) config('webhooks.connect_timeout', 5), (int) config('webhooks.response_bytes', 1024));
        } catch (WebhookUnreachable) {
            $delivery->fill(['duration_ms' => self::since($started), 'response_status' => null, 'response_body' => null]);
            $this->retry($delivery, 'unreachable');

            return;
        }

        $delivery->fill(['duration_ms' => self::since($started), 'response_status' => $response['status'], 'response_body' => $response['body']]);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $delivery->fill(['status' => WebhookDelivery::DELIVERED, 'error' => null, 'delivered_at' => CarbonImmutable::now()])->save();
            $subscription->fill(['consecutive_failures' => 0, 'last_delivery_at' => CarbonImmutable::now()])->save();

            return;
        }

        $this->retry($delivery, 'status');
    }

    /**
     * The JSON body of a stored payload: the envelope with `data` an
     * object even when empty.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function body(array $payload): array
    {
        return [...$payload, 'data' => (object) ($payload['data'] ?? [])];
    }

    private function retry(WebhookDelivery $delivery, string $error): void
    {
        $backoff = array_values((array) config('webhooks.backoff', [60]));

        if ($delivery->event_type === WebhookCatalogue::TEST || $delivery->attempts > count($backoff)) {
            $this->fail($delivery, $error, count: $delivery->event_type !== WebhookCatalogue::TEST);

            return;
        }

        $delay = (int) $backoff[$delivery->attempts - 1];
        $delivery->fill(['status' => WebhookDelivery::RETRYING, 'error' => $error, 'next_attempt_at' => CarbonImmutable::now()->addSeconds($delay)])->save();
        SendWebhook::dispatch($this->tenants->require(), $delivery->id)->delay($delay);
    }

    private function fail(WebhookDelivery $delivery, string $error, bool $count = true): void
    {
        $delivery->fill(['status' => WebhookDelivery::FAILED, 'error' => $error, 'next_attempt_at' => null])->save();

        if (! $count) {
            return;
        }

        // Counted in the database, so parallel workers never lose a failure.
        $failures = (int) DB::connection(TenantContext::CONNECTION)->table('webhook_subscriptions')->where('id', $delivery->subscription_id)
            ->where('active', true)->whereNull('archived_at')
            ->incrementEach(['consecutive_failures' => 1], ['updated_at' => CarbonImmutable::now()]);

        if ($failures === 0) {
            return;
        }

        $subscription = WebhookSubscription::query()->findOrFail($delivery->subscription_id);

        if ($subscription->consecutive_failures < (int) config('webhooks.disable_after', 10)) {
            return;
        }

        // Switched off once: only the update that turns it off notifies.
        $disabled = WebhookSubscription::query()->whereKey($subscription->id)->where('active', true)
            ->update(['active' => false, 'disabled_reason' => WebhookSubscription::FAILING, 'disabled_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($disabled !== 1) {
            return;
        }

        // AUD-01: switched off by the platform, recorded with the reason.
        $this->auditor->record('core.webhook.disable', $subscription, ['active' => true], ['active' => false, 'reason' => WebhookSubscription::FAILING, 'failures' => $subscription->consecutive_failures]);
        $this->subscriptions->flush();
        $this->notifier->send(new NotificationEvent(
            self::DISABLED_EVENT,
            $this->recipients($subscription),
            ['url' => $subscription->url_display, 'failures' => (string) $subscription->consecutive_failures],
            '/settings/webhooks/'.$subscription->id,
        ));
    }

    /** @return list<string> the tenant's webhook managers and the subscription's own user */
    private function recipients(WebhookSubscription $subscription): array
    {
        $managers = User::query()->where('status', User::STATUS_ACTIVE)
            ->whereIn('id', RoleAssignment::query()->where('scope_type', Scope::TENANT)->select('user_id'))
            ->orderBy('id')->get()
            ->filter(fn (User $user) => $user->can('core.webhook.manage', Scope::tenant()))
            ->modelKeys();
        $own = $this->principals->of($subscription)?->notifiableUserId();

        return array_values(array_unique(array_filter([...$managers, $own])));
    }

    private static function since(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
