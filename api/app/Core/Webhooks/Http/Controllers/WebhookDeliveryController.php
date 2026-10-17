<?php

namespace App\Core\Webhooks\Http\Controllers;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Delivery\WebhookFanOut;
use App\Core\Webhooks\Http\Requests\ListWebhookDeliveriesRequest;
use App\Core\Webhooks\Http\Requests\WebhookDeliveryRequest;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Principals\UserPrincipal;
use Illuminate\Http\JsonResponse;

/**
 * HOOK-01: the delivery log (`core.webhook.manage`, tenant scope): list
 * with filters, one delivery with its body and the receiver's answer, and
 * replay (a new delivery with the same event id and body, to the
 * subscription's current URL and secret).
 *
 * The body shown is filtered again for the reader: a manager who may not
 * see the event type gets no body, and fields their own rules hide are
 * left out (RBAC-05), whoever the subscription's principal is.
 */
class WebhookDeliveryController
{
    public function index(ListWebhookDeliveriesRequest $request): JsonResponse
    {
        $status = $request->validated('status');
        $page = WebhookDelivery::query()->with('subscription:id,url_display')
            ->when($request->validated('webhook'), fn ($q, $id) => $q->where('subscription_id', $id))
            ->when($status !== null && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->validated('type'), fn ($q, $type) => $q->where('event_type', $type))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 50));

        return response()->json([
            'data' => collect($page->items())->map(fn (WebhookDelivery $d) => self::summary($d))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(WebhookDeliveryRequest $request, WebhookCatalogue $catalogue, WebhookFanOut $fanOut): JsonResponse
    {
        $delivery = $request->delivery()->load('subscription:id,url_display');

        return response()->json(['data' => [
            ...self::summary($delivery),
            'payload' => $this->visiblePayload($delivery, $request->user(), $catalogue, $fanOut),
            'response_body' => $delivery->response_body,
        ]]);
    }

    public function replay(WebhookDeliveryRequest $request, WebhookFanOut $fanOut, Auditor $auditor): JsonResponse
    {
        $original = $request->delivery();
        $subscription = $original->subscription;

        if (! $subscription->isLive()) {
            throw new ApiException(409, 'webhook_inactive', __('webhooks.errors.webhook_inactive'));
        }

        // To the subscription's current URL and secret, as its current principal may see it now.
        $replay = $fanOut->single($subscription, $original->event_id, $original->event_type, $original->payload, $request->user(), $original->id, $original->occurrence);

        if ($replay === null) {
            throw new ApiException(409, 'not_visible', __('webhooks.errors.replay_not_visible'));
        }

        $auditor->record('core.webhook.replay', $subscription, null, ['delivery_id' => $original->id, 'replay_id' => $replay->id, 'event_id' => $original->event_id]);

        return response()->json(['data' => self::summary($replay->fresh(['subscription']) ?? $replay)], 202);
    }

    /** @return array<string, mixed> */
    public static function summary(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'webhook' => ['id' => $delivery->subscription_id, 'url_display' => $delivery->subscription?->url_display],
            'event_id' => $delivery->event_id,
            'event_type' => $delivery->event_type,
            'status' => $delivery->status,
            'attempts' => $delivery->attempts,
            'response_status' => $delivery->response_status,
            'duration_ms' => $delivery->duration_ms,
            'error' => $delivery->error,
            'next_attempt_at' => $delivery->next_attempt_at?->toIso8601String(),
            'last_attempt_at' => $delivery->last_attempt_at?->toIso8601String(),
            'delivered_at' => $delivery->delivered_at?->toIso8601String(),
            'replay_of' => $delivery->replay_of,
            'created_at' => $delivery->created_at?->toIso8601String(),
        ];
    }

    /**
     * The body as its reader may see it: nothing unless the reader may see
     * the event where it happened (the stored occurrence, the same check as
     * for a principal, also for types checked per document), and then
     * without the fields the reader's rules hide (RBAC-04, RBAC-05).
     *
     * @return array<string, mixed>|null
     */
    private function visiblePayload(WebhookDelivery $delivery, User $reader, WebhookCatalogue $catalogue, WebhookFanOut $fanOut): ?array
    {
        $payload = $delivery->payload;

        if ($delivery->event_type === WebhookCatalogue::TEST) {
            return $payload;
        }

        $type = $catalogue->find($delivery->event_type);
        $viewer = new UserPrincipal($reader);

        if ($type === null || ! $type->allows($viewer, $fanOut->occurrenceOf($delivery->occurrence, $payload))) {
            return null;
        }

        $payload['data'] = (object) $type->visibleData($viewer, (array) ($payload['data'] ?? []));

        return $payload;
    }
}
