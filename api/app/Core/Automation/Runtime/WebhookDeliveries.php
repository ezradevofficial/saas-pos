<?php

namespace App\Core\Automation\Runtime;

use App\Core\Automation\Actions\WebhookAction;
use App\Core\Automation\Jobs\SendWebhookDelivery;
use App\Core\Automation\Models\AutomationRule;
use App\Core\Automation\Models\WebhookDelivery;
use App\Core\Automation\Webhooks\WebhookRefused;
use App\Core\Automation\Webhooks\WebhookSender;
use App\Core\Automation\Webhooks\WebhookUnreachable;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Workflow\DocumentTypes\DocumentTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUTO-03 webhooks from the outbox, after the run committed. A delivery is
 * claimed (pending or retrying to sending, attempts + 1) so two workers
 * never send it twice. The first attempt fills `fields` from the committed
 * document, without the fields hidden from the rule's user (RBAC-05), and
 * stores the payload, so every attempt sends the same body with the same
 * `X-Webhook-Id` (run id:action index).
 *
 * A refused address or a 4xx answer (other than 408 and 429) fails at once;
 * no answer, a 5xx, 408 or 429 is tried again after the backoff, up to
 * `automation.webhook_attempts`. A delivery that fails for good alerts the
 * automation administrators.
 */
class WebhookDeliveries
{
    private const RETRY_STATUSES = [408, 429];

    public function __construct(
        private readonly WebhookSender $sender,
        private readonly DocumentTypeRegistry $types,
        private readonly FieldVisibility $visibility,
        private readonly FailureAlert $alert,
        private readonly TenantContext $tenants,
    ) {}

    public function deliver(string $deliveryId): void
    {
        $claimed = WebhookDelivery::query()->whereKey($deliveryId)
            ->whereIn('status', [WebhookDelivery::PENDING, WebhookDelivery::RETRYING])
            ->update(['status' => WebhookDelivery::SENDING, 'attempts' => DB::raw('attempts + 1'), 'next_attempt_at' => null, 'updated_at' => CarbonImmutable::now()]);

        if ($claimed !== 1) {
            return;
        }

        $delivery = WebhookDelivery::query()->with(['rule', 'run'])->findOrFail($deliveryId);
        $rule = $delivery->rule;
        $secret = $rule?->webhook_secret;

        if (! is_string($secret) || $secret === '') {
            $this->failed($delivery, __('automation.errors.no_webhook_secret'));

            return;
        }

        if (! array_key_exists('fields', $delivery->payload)) {
            $delivery->payload = $this->withFields($delivery->payload, $rule);
            $delivery->save();
        }

        try {
            $target = $this->sender->target($delivery->url);
        } catch (WebhookRefused $e) {
            $this->failed($delivery, __('automation.webhook.refused.'.$e->reason));

            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $response = $this->sender->send($target, $body, $secret, $delivery->webhookId());
        } catch (WebhookUnreachable) {
            $this->retry($delivery, __('automation.webhook.unreachable', ['url' => WebhookAction::shown($delivery->url)]));

            return;
        }

        $delivery->fill(['response_status' => $response['status'], 'response_body' => $response['body']]);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $delivery->fill(['status' => WebhookDelivery::DELIVERED, 'error' => null, 'delivered_at' => CarbonImmutable::now()])->save();

            return;
        }

        $message = __('automation.webhook.status', ['status' => $response['status']]);

        if ($response['status'] >= 500 || in_array($response['status'], self::RETRY_STATUSES, true)) {
            $this->retry($delivery, $message);
        } else {
            $this->failed($delivery, $message);
        }
    }

    /** @return array<string, mixed> */
    private function withFields(array $payload, AutomationRule $rule): array
    {
        $document = $payload['document'] ?? null;
        $type = is_array($document) ? $this->types->find((string) $document['type']) : null;
        $fields = [];

        if ($type !== null) {
            $actor = $rule->actorId() === null ? null : User::query()->find($rule->actorId());
            $fields = FieldVisibility::without($type->fieldValues((string) $document['id']), $this->visibility->hidden($actor, $type));
        }

        return [...$payload, 'occurred_at' => CarbonImmutable::now()->toIso8601ZuluString(), 'fields' => (object) $fields];
    }

    private function retry(WebhookDelivery $delivery, string $message): void
    {
        if ($delivery->attempts >= (int) config('automation.webhook_attempts', 3)) {
            $this->failed($delivery, $message);

            return;
        }

        $backoff = (array) config('automation.webhook_backoff', [60, 300]);
        $delay = (int) ($backoff[$delivery->attempts - 1] ?? end($backoff) ?: 60);

        $delivery->fill(['status' => WebhookDelivery::RETRYING, 'error' => $message, 'next_attempt_at' => CarbonImmutable::now()->addSeconds($delay)])->save();
        SendWebhookDelivery::dispatch($this->tenants->require(), $delivery->id)->delay($delay);
    }

    private function failed(WebhookDelivery $delivery, string $message): void
    {
        $delivery->fill(['status' => WebhookDelivery::FAILED, 'error' => $message, 'next_attempt_at' => null])->save();

        if ($delivery->rule !== null && $delivery->run !== null) {
            $this->alert->send($delivery->rule, $delivery->run, $message);
        }
    }
}
