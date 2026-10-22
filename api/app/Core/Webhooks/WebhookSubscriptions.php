<?php

namespace App\Core\Webhooks;

use App\Core\Api\Models\ApiKey;
use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Rbac\Scope;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use App\Core\Webhooks\Delivery\WebhookSignature;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\UserPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipal;
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
 * - sends(): whether the current tenant is active, memoised the same way.
 *   A suspended or closed tenant sends nothing (Phase 8 review M2): no
 *   fan-out, and deliveries already made wait (WebhookDeliverer) until it
 *   is active again.
 * - create, update, rotate, archive: audited as `core.webhook.*`, never
 *   with the URL or a secret. The secret is returned once, by create and
 *   rotate. A rotation keeps the previous secret signing for
 *   `webhooks.rotation_overlap_hours`.
 */
class WebhookSubscriptions
{
    /** The principal type of a subscription owned by an API key (a marketplace install's, ADR 024). */
    public const KEY_PRINCIPAL = 'api_key';

    /** @var array<string, array<string, true>> tenant id => subscribed type keys */
    private array $memo = [];

    /** @var array<string, bool> tenant id => active */
    private array $active = [];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly Auditor $auditor,
        private readonly WebhookCatalogue $catalogue,
    ) {}

    /** Whether the current tenant has a live subscription to $type. */
    public function wants(string $type): bool
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null || ! $this->sends()) {
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

    /**
     * HOOK-01, ADM-01c (Phase 8 review M2): whether the current tenant may
     * send webhooks: only while it is active. Read once per request or job.
     */
    public function sends(): bool
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            return false;
        }

        return $this->active[$tenantId] ??= Tenant::query()->whereKey($tenantId)->value('status') === 'active';
    }

    public function flush(): void
    {
        $this->memo = [];
        $this->active = [];
    }

    /**
     * @param  array{url: string, description?: ?string, events: list<string>, active?: bool, company_id?: ?string, branch_id?: ?string}  $values
     * @return array{0: WebhookSubscription, 1: string} the subscription and its secret (shown once)
     */
    public function create(array $values, User $by): array
    {
        $secret = WebhookSignature::newSecret();

        $subscription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($values, $by, $secret) {
            // The cap is counted under a per-tenant lock held to commit, so two requests never both pass it.
            DB::connection(TenantContext::CONNECTION)->select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['webhook_subscriptions:'.$this->tenants->require()]);
            $max = (int) config('webhooks.max_subscriptions', 25);

            if (WebhookSubscription::query()->whereNull('archived_at')->count() >= $max) {
                $message = __('webhooks.errors.too_many', ['count' => $max]);

                throw new ApiException(422, 'too_many_webhooks', $message, ['url' => [$message]]);
            }

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

    /**
     * MKT-02 (ADR 024): the subscription of a marketplace install, owned by
     * its key (principal `api_key`) and signed with the app's own secret,
     * which only its developer holds. Not counted against the tenant's cap:
     * there is one per install, and it goes when the app is uninstalled.
     *
     * @param  array{url: string, description?: ?string, events: list<string>, company_id?: ?string, branch_id?: ?string}  $values
     */
    public function createForKey(ApiKey $key, array $values, User $by, string $secret): WebhookSubscription
    {
        $subscription = DB::connection(TenantContext::CONNECTION)->transaction(function () use ($key, $values, $by, $secret) {
            $subscription = WebhookSubscription::create([
                'principal_type' => self::KEY_PRINCIPAL,
                'principal_id' => $key->id,
                'url' => $values['url'],
                'url_display' => WebhookEndpoints::display($values['url']),
                'description' => $values['description'] ?? null,
                'events' => array_values(array_unique($values['events'])),
                'active' => true,
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

        return $subscription;
    }

    /**
     * MKT-02: the app's developer rolled its signing secret; the previous
     * one keeps signing for the overlap, as for a rotation here.
     */
    public function rollSecret(WebhookSubscription $subscription, string $secret, ?CarbonImmutable $previousUntil): void
    {
        $subscription->forceFill([
            // No overlap when the developer ended the previous key at once.
            'previous_secret' => $previousUntil === null ? null : $subscription->secret,
            'previous_secret_expires_at' => $previousUntil,
            'secret' => $secret,
            'secret_rotated_at' => CarbonImmutable::now(),
        ])->save();

        $this->auditor->record('core.webhook.rotate_secret', $subscription, null, ['previous_valid_until' => $previousUntil?->toIso8601String(), 'by_app' => true]);
    }

    /**
     * A marketplace install's subscription (security review, ADR 024): its
     * URL, events, filter and key follow the install's consent and are
     * changed only by the install itself. People may only switch it off.
     */
    public static function isAppOwned(WebhookSubscription $subscription): bool
    {
        return $subscription->principal_type === self::KEY_PRINCIPAL;
    }

    private static function appOwned(): ApiException
    {
        return new ApiException(403, 'webhook_app_owned', __('webhooks.errors.app_owned'));
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  bool  $system  the install's own change (Installations), not a person's
     */
    public function update(WebhookSubscription $subscription, array $values, User $by, bool $system = false): WebhookSubscription
    {
        if (! $system && self::isAppOwned($subscription) && $values !== ['active' => false]) {
            throw self::appOwned();
        }

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

    /**
     * Whether $user is the subscription's principal (only they change what
     * it sends and where: URL, events, filter).
     */
    public static function isPrincipal(WebhookSubscription $subscription, User $user): bool
    {
        return $subscription->principal_type === UserPrincipal::TYPE && $subscription->principal_id === $user->id;
    }

    /**
     * The scope a subscription receives events of: its branch, its company,
     * or the whole tenant.
     */
    public static function scopeOf(?string $companyId, ?string $branchId): Scope
    {
        return match (true) {
            $branchId !== null => Scope::branch($branchId),
            $companyId !== null => Scope::company($companyId),
            default => Scope::tenant(),
        };
    }

    /**
     * The indexes of $events $principal may not receive at $scope (types
     * checked per document, with no permission, are checked when they happen).
     *
     * @param  list<string>  $events
     * @return list<int>
     */
    public function invisibleEvents(WebhookPrincipal $principal, array $events, Scope $scope): array
    {
        $refused = [];

        foreach (array_values($events) as $index => $key) {
            $permission = $this->catalogue->find((string) $key)?->permission;

            if ($permission !== null && ! $principal->can($permission, $scope)) {
                $refused[] = $index;
            }
        }

        return $refused;
    }

    /**
     * The Owner takes over a subscription made by someone else: it sends as
     * them from now on. Its events are checked against them at its scope
     * first; audited (`core.webhook.take_over`).
     */
    public function takeOver(WebhookSubscription $subscription, User $owner): WebhookSubscription
    {
        if (self::isAppOwned($subscription)) {
            throw self::appOwned();
        }

        $principal = new UserPrincipal($owner);

        if ($this->invisibleEvents($principal, $subscription->events, self::scopeOf($subscription->company_id, $subscription->branch_id)) !== []) {
            $message = __('webhooks.errors.take_over_not_visible');

            throw new ApiException(422, 'webhook_events_not_visible', $message, ['events' => [$message]]);
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($subscription, $owner) {
            $before = ['principal_type' => $subscription->principal_type, 'principal_id' => $subscription->principal_id];
            $subscription->fill(['principal_type' => UserPrincipal::TYPE, 'principal_id' => $owner->id, 'updated_by' => $owner->id])->save();
            $this->auditor->record('core.webhook.take_over', $subscription, $before, ['principal_type' => UserPrincipal::TYPE, 'principal_id' => $owner->id]);
        });

        $this->flush();

        return $subscription;
    }

    /** @return array{0: WebhookSubscription, 1: string} the subscription and its new secret (shown once) */
    public function rotate(WebhookSubscription $subscription, User $by): array
    {
        if (self::isAppOwned($subscription)) {
            throw self::appOwned();
        }

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
        if (self::isAppOwned($subscription)) {
            throw self::appOwned();
        }

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
