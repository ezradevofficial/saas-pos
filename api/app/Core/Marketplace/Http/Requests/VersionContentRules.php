<?php

namespace App\Core\Marketplace\Http\Requests;

use App\Core\Api\ApiScopes;
use App\Core\Marketplace\AppPricing;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use App\Core\Webhooks\Delivery\WebhookEndpoints;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * MKT-01: what a developer writes on a version. Texts are stored once.
 * Redirect URIs are HTTPS, or a loopback address over HTTP for native and
 * local apps (RFC 8252), never with a fragment; the webhook URL follows the
 * webhook rules (HTTPS, public address, ADR 021); scopes come from the
 * public API's catalogue and events from the webhook catalogue.
 */
trait VersionContentRules
{
    /** @return array<string, mixed> */
    protected function contentRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'name' => [$presence, 'string', 'min:2', 'max:80'],
            'tagline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'description' => [$presence, 'string', 'max:5000'],
            'category' => [$presence, 'string', Rule::in(MarketplaceAppVersion::CATEGORIES)],
            'website' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],
            'privacy_url' => ['sometimes', 'nullable', 'string', 'max:255', 'url:https'],
            'support_email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'redirect_uris' => ['sometimes', 'array', 'max:'.(int) config('marketplace.apps.max_redirect_uris', 5)],
            'redirect_uris.*' => ['string', 'max:2000'],
            'scopes' => ['sometimes', 'array', 'max:'.(int) config('marketplace.apps.max_scopes', 60)],
            'scopes.*' => ['string', Rule::in(app(ApiScopes::class)->catalogue())],
            'webhook_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'webhook_events' => ['sometimes', 'array', 'max:50'],
            'webhook_events.*' => ['string', Rule::in(array_values(array_diff(array_map(fn ($type) => $type->key, app(WebhookCatalogue::class)->all()), [WebhookCatalogue::TEST])))],
            'pricing' => [$presence, 'array'],
        ];
    }

    protected function validateContent(Validator $validator): void
    {
        foreach ((array) $this->input('redirect_uris', []) as $index => $uri) {
            if (is_string($uri) && ! self::validRedirect($uri)) {
                $validator->errors()->add("redirect_uris.{$index}", __('marketplace.validation.redirect_uri'));
            }
        }

        $webhook = $this->input('webhook_url');

        if (is_string($webhook) && $webhook !== '' && ($reason = app(WebhookEndpoints::class)->problem($webhook)) !== null) {
            $validator->errors()->add('webhook_url', __('webhooks.url.'.$reason));
        }

        if ($this->has('pricing')) {
            foreach (AppPricing::problems($this->input('pricing')) as $field => $key) {
                $validator->errors()->add($field, __($key));
            }
        }
    }

    /** HTTPS with a host, or HTTP to a loopback address; never a fragment or credentials. */
    public static function validRedirect(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
