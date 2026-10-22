<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Api\ApiScopes;
use App\Core\Marketplace\AppPricing;
use App\Core\Marketplace\Developers;
use App\Core\Marketplace\Http\Requests\DeveloperRequest;
use App\Core\Marketplace\Http\Requests\SaveDeveloperRequest;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\Models\MarketplaceDeveloper;
use App\Core\Marketplace\ScopeDescriptions;
use App\Core\Webhooks\Catalogue\WebhookCatalogue;
use Illuminate\Http\JsonResponse;

/**
 * MKT-01: the tenant's developer account (developer portal), and what an
 * app may ask for: the public API's scopes (every module, since an app is
 * installed in other tenants), the webhook events, the categories and the
 * currencies prices are set in.
 */
class DeveloperController
{
    public function __construct(private readonly Developers $developers) {}

    public function show(DeveloperRequest $request, ApiScopes $scopes, WebhookCatalogue $catalogue, ScopeDescriptions $descriptions): JsonResponse
    {
        $all = $scopes->catalogue();

        return new JsonResponse(['data' => [
            'developer' => self::present($this->developers->current()),
            'catalogue' => [
                'scopes' => $all,
                // MKT-01: each scope in the plain words the consent screen uses (en/fr), for the scope picker.
                'scope_labels' => collect($descriptions->describe($all))->mapWithKeys(fn (array $d) => [$d['scope'] => $d['label']])->all(),
                'webhook_events' => array_values(array_diff(array_map(fn ($type) => $type->key, $catalogue->all()), [WebhookCatalogue::TEST])),
                'categories' => MarketplaceAppVersion::CATEGORIES,
                'currencies' => AppPricing::CURRENCIES,
                'share_percent' => (int) config('marketplace.developer_share_percent', 80),
            ],
        ]]);
    }

    public function save(SaveDeveloperRequest $request): JsonResponse
    {
        $developer = $this->developers->register($request->user(), $request->safe()->only(['name', 'email', 'website']));

        return new JsonResponse(['data' => ['developer' => self::present($developer)]]);
    }

    /** @return array<string, mixed>|null */
    private static function present(?MarketplaceDeveloper $developer): ?array
    {
        return $developer === null ? null : [
            'id' => $developer->id,
            'name' => $developer->name,
            'email' => $developer->email,
            'website' => $developer->website,
            'status' => $developer->status,
            'terms_accepted_at' => $developer->terms_accepted_at?->toIso8601String(),
        ];
    }
}
