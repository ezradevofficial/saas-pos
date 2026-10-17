<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use App\Core\Webhooks\Models\WebhookSubscription;
use App\Core\Webhooks\Principals\UserPrincipal;
use App\Core\Webhooks\Principals\WebhookPrincipals;
use App\Core\Webhooks\WebhookSubscriptions;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * HOOK-01: create (POST webhooks) or change (PATCH webhooks/{webhook}) a
 * subscription. The URL must pass the SSRF rules (WebhookEndpoints: HTTPS,
 * no credentials, no private or loopback address); events must be
 * available types (their module active, RBAC-08) the signed-in user may
 * receive as the principal at the subscription's scope (its branch, its
 * company or the whole tenant); the filter is one company or one branch of
 * this tenant (an id of another tenant is not found: row-level security).
 * Only the principal changes the URL, events or filter (403
 * `webhook_not_owner`); others may change the description and switch it on
 * or off, and the Owner may take it over.
 */
class SaveWebhookRequest extends WebhookRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge(['url' => trim($this->input('url'))]);
        }
    }

    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';
        $types = array_map(fn (WebhookEventType $type) => $type->key, app(WebhookCatalogue::class)->available());

        return [
            'url' => [$required, 'string', 'max:2000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
            'events' => [$required, 'array', 'min:1', 'max:50'],
            'events.*' => ['required', 'string', 'distinct', Rule::in($types)],
            'active' => ['sometimes', 'boolean'],
            'company_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('companies', 'id')],
            'branch_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('branches', 'id'), 'prohibits:company_id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var User $user */
            $user = $this->user();
            $subscription = $this->isMethod('POST') ? null : $this->subscription();

            // Only the principal changes what a subscription sends and where; the Owner may take it over first.
            if ($subscription !== null && ! WebhookSubscriptions::isPrincipal($subscription, $user) && $this->changesDelivery($subscription)) {
                throw new ApiException(403, 'webhook_not_owner', __('webhooks.errors.not_owner'));
            }

            if ($this->has('url') && ($reason = app(WebhookEndpoints::class)->problem($this->input('url'))) !== null) {
                $validator->errors()->add('url', __('webhooks.url.'.$reason));
            }

            // Events are checked against the principal (who receives them) at the subscription's scope.
            $principal = $subscription === null ? new UserPrincipal($user) : app(WebhookPrincipals::class)->of($subscription);
            [$companyId, $branchId] = $this->filter($subscription);
            $events = (array) $this->input('events', $subscription?->events ?? []);
            $refused = $principal === null ? array_keys($events) : app(WebhookSubscriptions::class)->invisibleEvents($principal, $events, WebhookSubscriptions::scopeOf($companyId, $branchId));

            foreach ($refused as $index) {
                $validator->errors()->add("events.{$index}", __('webhooks.errors.event_not_visible'));
            }
        }];
    }

    /** Whether the request changes the URL, the events or the filter of $subscription. */
    private function changesDelivery(WebhookSubscription $subscription): bool
    {
        if (is_string($this->input('url')) && $this->input('url') !== '') {
            return true;
        }

        if ($this->has('events')) {
            $events = array_values(array_unique((array) $this->input('events')));
            sort($events);
            $current = $subscription->events;
            sort($current);

            if ($events !== $current) {
                return true;
            }
        }

        return $this->filter($subscription) !== [$subscription->company_id, $subscription->branch_id];
    }

    /** @return array{0: ?string, 1: ?string} the company and branch filter after this request */
    private function filter(?WebhookSubscription $subscription): array
    {
        $companyId = $this->has('company_id') ? $this->input('company_id') : $subscription?->company_id;
        $branchId = $this->has('branch_id') ? $this->input('branch_id') : $subscription?->branch_id;

        // One filter at most: setting one clears the other (WebhookSubscriptions::update).
        if ($this->has('company_id') && $companyId !== null) {
            $branchId = null;
        }
        if ($this->has('branch_id') && $branchId !== null) {
            $companyId = null;
        }

        return [$companyId, $branchId];
    }

    /** @return array<string, mixed> */
    public function values(): array
    {
        return $this->safe()->only(['url', 'description', 'events', 'active', 'company_id', 'branch_id']);
    }

    public function attributes(): array
    {
        return [
            'url' => __('webhooks.attributes.url'),
            'description' => __('webhooks.attributes.description'),
            'events' => __('webhooks.attributes.events'),
            'events.*' => __('webhooks.attributes.event'),
            'company_id' => __('webhooks.attributes.company_id'),
            'branch_id' => __('webhooks.attributes.branch_id'),
        ];
    }
}
