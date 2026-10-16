<?php

namespace App\Core\Webhooks\Http\Requests;

use App\Core\Identity\Models\User;
use App\Core\Rbac\ScopeResolver;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Catalogue\WebhookEventType;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * HOOK-01: create (POST webhooks) or change (PATCH webhooks/{webhook}) a
 * subscription. The URL must pass the SSRF rules (WebhookEndpoints: HTTPS,
 * no credentials, no private or loopback address); events must be
 * available types (their module active, RBAC-08) the signed-in user may
 * see somewhere, as deliveries are made as that user; the filter is one
 * company or one branch of this tenant (an id of another tenant is not
 * found: row-level security).
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

            if ($this->has('url') && ($reason = app(WebhookEndpoints::class)->problem($this->input('url'))) !== null) {
                $validator->errors()->add('url', __('webhooks.url.'.$reason));
            }

            /** @var User $user */
            $user = $this->user();
            $catalogue = app(WebhookCatalogue::class);

            foreach ((array) $this->input('events', []) as $index => $key) {
                $permission = $catalogue->find((string) $key)?->permission;

                if ($permission !== null && ! app(ScopeResolver::class)->can($user, $permission)) {
                    $validator->errors()->add("events.{$index}", __('webhooks.errors.event_not_visible'));
                }
            }
        }];
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
