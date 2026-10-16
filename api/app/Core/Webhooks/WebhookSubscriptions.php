<?php

namespace App\Core\Webhooks;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\UserPrincipal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * HOOK-01: webhook subscriptions (docs/adr/021-webhooks.md).
 *
 * - subscribedTypes(): the event types the current tenant has a live
 *   subscription to, in one query memoised per tenant for the request or
 *   job (flushed before every job, on a new request and on every change
 *   here), so raising an event costs nothing per occurrence and a tenant
 *   without subscriptions never queues anything (UploadQueryBudgetTest).
 * - create, update, rotate, archive: audited as `core.webhook.*`, never
 *   with the URL or a secret. The secret is returned once, by create and
 *   rotate. A rotation keeps the previous secret signing for
 *   `webhooks.rotation_overlap_hours`.
 */
class WebhookSubscriptions
{
    /** @var array<string, array<string, true>> tenant id => subscribed type keys */
    private array $memo = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
    ) {}

    /** Whether the current tenant has a live subscription to $type. */
    public function wants(string $type): bool
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return false;
        }

        $this->memo[$tenantId] ??= array_fill_keys(
            DB::connection(TenantContext::CONNECTION)->table('webhook_subscriptions')
                ->where('active', true)->whereNull('archived_at')
                ->selectRaw('distinct jsonb_array_elements_text(events) as type')
                ->pluck('type')->map(fn ($type) => (string) $type)->all(),
            true,
        );

        return isset($this->memo[$tenantId][$type]);
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * @param  array{url: string, description?: ?string, events: list<string>, active?: bool, company_id?: ?string, branch_id?: ?string}  $values
     * @return array{0: WebhookSubscription, 1: string} the subscription and its secret (shown once)
     */
    public function create(array $values, User $by): array
    {
        $max = (int) config('webhooks.max_subscriptions', 25);

        if (WebhookSubscription::query()->whereNull('archived_at')->count() >= $max) {
            $message = __('webhooks.errors.too_many', ['count' => $max]);

            throw new ApiException(422, 'too_many_webhooks', $message, ['url' => [$message]]);
        }

        $secret = WebhookSignature::newSecret();

        $subscription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($values, $by, $secret) {
            $subscription = WebhookSubscription::create([
                'principal_type' => UserPrincipal::TYPE,
                'principal_id' => $by->id,
                'url' => $values['url'],
                'url_display' => WebhookEndpoints::display($values['url']),
                'description' => $values['description'] ?? null,
                'events' => array_values(array_unique($values['events'])),
                'active' => $values['active'] ?? true,
                'company_id' => $values['company_id'] ?? null,
                'branch_id' => $values['branch_id'] ?? null,
                'secret' => $secret,
                'secret_rotated_at' => CarbonImmutable::now(),
                'created_by' => $by->id,
            ]);

            $this->auditor->record('core.webhook.create', $subscription, null, $this->audited($subscription));

            return $subscription;
        });

        $this->flush();

        return [$subscription, $secret];
    }

    /** @param  array<string, mixed>  $values */
    public function update(WebhookSubscription $subscription, array $values, User $by): WebhookSubscription
    {
        $before = $this->audited($subscription);

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $values, $by, $before) {
            if (array_key_exists('url', $values) && is_string($values['url']) && $values['url'] !== '') {
                $subscription->url = $values['url'];
                $subscription->url_display = WebhookEndpoints::display($values['url']);
            }

            foreach (['description', 'company_id', 'branch_id'] as $field) {
                if (array_key_exists($field, $values)) {
                    $subscription->{$field} = $values[$field];
                }
            }

            // One filter at most: setting a company clears the branch, and the reverse.
            if (array_key_exists('company_id', $values) && $values['company_id'] !== null) {
                $subscription->branch_id = null;
            }
            if (array_key_exists('branch_id', $values) && $values['branch_id'] !== null) {
                $subscription->company_id = null;
            }

            if (array_key_exists('events', $values)) {
                $subscription->events = array_values(array_unique($values['events']));
            }

            if (array_key_exists('active', $values)) {
                $active = (bool) $values['active'];

                // Switching back on starts the failure count afresh.
                if ($active && ! $subscription->active) {
                    $subscription->fill(['consecutive_failures' => 0, 'disabled_reason' => null, 'disabled_at' => null]);
                }

                $subscription->active = $active;
            }

            $urlChanged = $subscription->isDirty('url');
            $subscription->updated_by = $by->id;
            $subscription->save();

            $after = $this->audited($subscription);
            $this->auditor->record('core.webhook.update', $subscription, $before, [...$after, ...($urlChanged ? ['url_changed' => true] : [])]);
        });

        $this->flush();

        return $subscription;
    }

    /** @return array{0: WebhookSubscription, 1: string} the subscription and its new secret (shown once) */
    public function rotate(WebhookSubscription $subscription, User $by): array
    {
        $secret = WebhookSignature::newSecret();
        $now = CarbonImmutable::now();

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $by, $secret, $now) {
            $subscription->fill([
                'previous_secret' => $subscription->secret,
                'previous_secret_expires_at' => $now->addHours((int) config('webhooks.rotation_overlap_hours', 24)),
                'secret' => $secret,
                'secret_rotated_at' => $now,
                'updated_by' => $by->id,
            ])->save();

            $this->auditor->record('core.webhook.rotate_secret', $subscription, null, ['previous_valid_until' => $subscription->previous_secret_expires_at?->toIso8601String()]);
        });

        return [$subscription, $secret];
    }

    public function archive(WebhookSubscription $subscription, User $by): WebhookSubscription
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $by) {
            $before = $this->audited($subscription);
            $subscription->fill(['active' => false, 'archived_at' => CarbonImmutable::now(), 'updated_by' => $by->id])->save();
            $this->auditor->record('core.webhook.archive', $subscription, $before, ['archived' => true]);
        });

        $this->flush();

        return $subscription;
    }

    /** @return array<string, mixed> what the audit log keeps of a subscription: never the URL's query or a secret */
    private function audited(WebhookSubscription $subscription): array
    {
        return [
            'url' => $subscription->url_display,
            'description' => $subscription->description,
            'events' => $subscription->events,
            'active' => $subscription->active,
            'company_id' => $subscription->company_id,
            'branch_id' => $subscription->branch_id,
        ];
    }
}
