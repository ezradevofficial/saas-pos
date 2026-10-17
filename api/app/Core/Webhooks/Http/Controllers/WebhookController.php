<?php

namespace App\Core\Webhooks\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Delivery\WebhookFanOut;
use App\Core\Webhooks\Http\Requests\SaveWebhookRequest;
use App\Core\Webhooks\Http\Requests\TakeOverWebhookRequest;
use App\Core\Webhooks\Http\Requests\WebhookRequest;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use App\Core\Webhooks\WebhookSubscriptions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * HOOK-01: Settings → Webhooks (`core.webhook.manage`, tenant scope): the
 * catalogue of events this tenant may subscribe to (modules active),
 * subscriptions (archived ones left out), create and change them, rotate
 * the signing secret (returned once, as on create), send a test event, and
 * archive. The URL is write-only: only `url_display` (no query) is shown.
 */
class WebhookController
{
    public function __construct(
        private readonly WebhookSubscriptions $subscriptions,
        private readonly WebhookPrincipals $principals,
    ) {}

    public function catalogue(WebhookRequest $request, WebhookCatalogue $catalogue): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn (WebhookEventType $type) => [
                'key' => $type->key,
                'module' => $type->module,
                'fields' => $type->fields,
            ], $catalogue->available()),
            'meta' => [
                'backoff' => array_values((array) config('webhooks.backoff')),
                'disable_after' => (int) config('webhooks.disable_after'),
                'retention_days' => (int) config('webhooks.retention_days'),
                'rotation_overlap_hours' => (int) config('webhooks.rotation_overlap_hours'),
                'max_subscriptions' => (int) config('webhooks.max_subscriptions'),
            ],
        ]);
    }

    public function index(WebhookRequest $request): JsonResponse
    {
        $subscriptions = WebhookSubscription::query()->whereNull('archived_at')->orderBy('created_at')->get();

        return response()->json(['data' => $subscriptions->map(fn (WebhookSubscription $s) => $this->present($s, $request))->values()]);
    }

    public function show(WebhookRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($request->subscription(), $request)]);
    }

    public function store(SaveWebhookRequest $request): JsonResponse
    {
        [$subscription, $secret] = $this->subscriptions->create($request->values(), $request->user());

        return response()->json(['data' => [...$this->present($subscription, $request), 'secret' => $secret]], 201);
    }

    public function update(SaveWebhookRequest $request): JsonResponse
    {
        $subscription = $this->subscriptions->update($request->subscription(), $request->values(), $request->user());

        return response()->json(['data' => $this->present($subscription, $request)]);
    }

    /** The Owner takes over someone else's subscription (it sends as them from now on). */
    public function takeOver(TakeOverWebhookRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->subscriptions->takeOver($request->subscription(), $request->user()), $request)]);
    }

    public function rotate(WebhookRequest $request): JsonResponse
    {
        [$subscription, $secret] = $this->subscriptions->rotate($request->subscription(), $request->user());

        return response()->json(['data' => [...$this->present($subscription, $request), 'secret' => $secret]]);
    }

    public function archive(WebhookRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->subscriptions->archive($request->subscription(), $request->user()), $request)]);
    }

    /** A `webhook.test.v1` event to this subscription only, tried once (whatever it subscribes to). */
    public function test(WebhookRequest $request, WebhookFanOut $fanOut, Auditor $auditor): JsonResponse
    {
        $subscription = $request->subscription();
        $eventId = (string) Str::uuid7();
        $payload = WebhookFanOut::envelope($eventId, WebhookCatalogue::TEST, CarbonImmutable::now()->toIso8601ZuluString(), [
            'message' => __('webhooks.test_message'),
            'webhook_id' => $subscription->id,
        ]);
        $delivery = $fanOut->single($subscription, $eventId, WebhookCatalogue::TEST, $payload, $request->user());
        if ($delivery === null) {
            throw new ApiException(409, 'principal_inactive', __('webhooks.errors.principal_inactive'));
        }

        $auditor->record('core.webhook.test', $subscription, null, ['delivery_id' => $delivery->id]);

        return response()->json(['data' => WebhookDeliveryController::summary($delivery->fresh() ?? $delivery)], 202);
    }

    /** @return array<string, mixed> */
    private function present(WebhookSubscription $subscription, WebhookRequest $request): array
    {
        $principal = $this->principals->of($subscription);

        return [
            'id' => $subscription->id,
            'url_display' => $subscription->url_display,
            'description' => $subscription->description,
            'events' => $subscription->events,
            'active' => $subscription->active,
            'company_id' => $subscription->company_id,
            'branch_id' => $subscription->branch_id,
            'principal' => [
                'type' => $subscription->principal_type,
                'id' => $subscription->principal_id,
                'name' => $principal?->name(),
                'active' => $principal?->isActive() ?? false,
                // Only the principal changes the URL, events or filter; the Owner may take it over.
                'is_me' => WebhookSubscriptions::isPrincipal($subscription, $request->user()),
            ],
            'consecutive_failures' => $subscription->consecutive_failures,
            'disabled_reason' => $subscription->disabled_reason,
            'disabled_at' => $subscription->disabled_at?->toIso8601String(),
            'secret_rotated_at' => $subscription->secret_rotated_at?->toIso8601String(),
            'previous_secret_expires_at' => $subscription->previous_secret_expires_at?->isFuture() ? $subscription->previous_secret_expires_at->toIso8601String() : null,
            'last_delivery_at' => $subscription->last_delivery_at?->toIso8601String(),
            'created_at' => $subscription->created_at?->toIso8601String(),
        ];
    }
}
