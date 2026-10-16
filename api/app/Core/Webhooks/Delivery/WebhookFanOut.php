<?php

namespace App\Core\Webhooks\Delivery;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Branch;
use App\Core\Tenancy\Models\Location;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Catalogue\WebhookOccurrence;
use App\Core\Webhooks\Jobs\SendWebhook;
use App\Core\Webhooks\Models\WebhookDelivery;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\WebhookPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use Illuminate\Support\Facades\DB;

/**
 * HOOK-01: turns one captured event into deliveries, in its tenant (the
 * fan-out job). The type must still be active (RBAC-08); its `resolve`
 * step may add documented fields or the scope from the module's own
 * tables; the scope is completed (a location's branch and company).
 *
 * Each live subscription to the type gets one delivery when its company or
 * branch filter matches and its principal may see the event at its scope
 * (RBAC-04). The stored body is the envelope with `data` already without
 * the fields the principal's rules hide (RBAC-05), so hidden values are
 * never stored or sent. The rows are written in one transaction and each
 * is sent by its own job once committed.
 */
class WebhookFanOut
{
    public function __construct(
        private readonly WebhookCatalogue $catalogue,
        private readonly WebhookPrincipals $principals,
    ) {}

    /**
     * @param  array<string, mixed>  $captured  WebhookOccurrence::toArray()
     * @return list<WebhookDelivery>
     */
    public function fanOut(string $typeKey, string $eventId, string $occurredAt, array $captured): array
    {
        $subscriptions = WebhookSubscription::query()->where('active', true)->whereNull('archived_at')
            ->whereJsonContains('events', $typeKey)->orderBy('created_at')->get();
        $type = $this->catalogue->active($typeKey);

        if ($subscriptions->isEmpty() || $type === null) {
            return [];
        }

        $occurrence = WebhookOccurrence::fromArray($captured);

        if ($type->resolve !== null) {
            $occurrence = ($type->resolve)($occurrence);

            if ($occurrence === null) {
                return [];
            }
        }

        $occurrence = $this->completeScope($occurrence);
        $rows = [];

        foreach ($subscriptions as $subscription) {
            if (! $this->matches($subscription, $occurrence)) {
                continue;
            }

            $principal = $this->principals->of($subscription);

            if ($principal === null || ! $principal->isActive() || ! $type->allows($principal, $occurrence)) {
                continue;
            }

            $rows[] = [$subscription, self::envelope($eventId, $type->key, $occurredAt, $type->visibleData($principal, $occurrence->data))];
        }

        if ($rows === []) {
            return [];
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($rows, $eventId, $type) {
            $deliveries = [];

            foreach ($rows as [$subscription, $payload]) {
                $delivery = WebhookDelivery::create([
                    'subscription_id' => $subscription->id,
                    'event_id' => $eventId,
                    'event_type' => $type->key,
                    'payload' => $payload,
                    'status' => WebhookDelivery::PENDING,
                ]);
                SendWebhook::dispatch((string) $delivery->tenant_id, $delivery->id)->afterCommit();
                $deliveries[] = $delivery;
            }

            return $deliveries;
        });
    }

    /**
     * A delivery to $subscription outside any captured event: the test
     * event, or a replay of $original (same event id and body, filtered
     * again through the principal's current rules). Null when the principal
     * may no longer see it.
     */
    public function single(WebhookSubscription $subscription, string $eventId, string $typeKey, array $payload, ?User $by, ?string $replayOf = null): ?WebhookDelivery
    {
        $type = $this->catalogue->find($typeKey);
        $principal = $this->principals->of($subscription);

        if ($principal === null || ! $principal->isActive()) {
            return null;
        }

        if ($type !== null && $typeKey !== WebhookCatalogue::TEST) {
            if ($this->catalogue->active($typeKey) === null || ! $this->stillVisible($type, $principal, $payload)) {
                return null;
            }

            $payload['data'] = (object) $type->visibleData($principal, (array) ($payload['data'] ?? []));
        }

        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $eventId, $typeKey, $payload, $by, $replayOf) {
            $delivery = WebhookDelivery::create([
                'subscription_id' => $subscription->id,
                'event_id' => $eventId,
                'event_type' => $typeKey,
                'payload' => $payload,
                'status' => WebhookDelivery::PENDING,
                'replay_of' => $replayOf,
                'requested_by' => $by?->id,
            ]);
            SendWebhook::dispatch((string) $delivery->tenant_id, $delivery->id)->afterCommit();

            return $delivery;
        });
    }

    /** The body every delivery sends (Standard Webhooks style envelope). */
    public static function envelope(string $eventId, string $type, string $occurredAt, array $data): array
    {
        return ['id' => $eventId, 'type' => $type, 'occurred_at' => $occurredAt, 'data' => (object) $data];
    }

    /** A replay is sent only while the principal may still see the event (its scope is in the stored data). */
    private function stillVisible(WebhookEventType $type, WebhookPrincipal $principal, array $payload): bool
    {
        $data = (array) ($payload['data'] ?? []);
        $occurrence = $this->completeScope(new WebhookOccurrence(
            $data,
            is_string($data['company_id'] ?? null) ? $data['company_id'] : null,
            is_string($data['branch_id'] ?? null) ? $data['branch_id'] : null,
            is_string($data['location_id'] ?? null) ? $data['location_id'] : null,
            isset($data['document_type'], $data['document_id']) ? ['type' => (string) $data['document_type'], 'id' => (string) $data['document_id']] : null,
        ));

        return $type->allows($principal, $occurrence);
    }

    private function matches(WebhookSubscription $subscription, WebhookOccurrence $occurrence): bool
    {
        if ($subscription->company_id !== null && $subscription->company_id !== $occurrence->companyId) {
            return false;
        }

        return $subscription->branch_id === null || $subscription->branch_id === $occurrence->branchId;
    }

    private function completeScope(WebhookOccurrence $occurrence): WebhookOccurrence
    {
        if ($occurrence->locationId !== null && ($occurrence->branchId === null || $occurrence->companyId === null)) {
            $location = Location::query()->with('branch')->find($occurrence->locationId);
            $occurrence = $occurrence->at($location?->branch?->company_id, $location?->branch_id);
        }

        if ($occurrence->branchId !== null && $occurrence->companyId === null) {
            $occurrence = $occurrence->at(Branch::query()->whereKey($occurrence->branchId)->value('company_id'));
        }

        return $occurrence;
    }
}
