<?php

namespace App\Core\Api\Http\Controllers;

use App\Core\Api\ApiKeys;
use App\Core\Api\ApiScopes;
use App\Core\Api\ApiUsage;
use App\Core\Api\Http\Requests\ApiKeyRequest;
use App\Core\Api\Http\Requests\ApiKeyUsageRequest;
use App\Core\Api\Http\Requests\ListApiKeysRequest;
use App\Core\Api\Http\Requests\RevokeApiKeyRequest;
use App\Core\Api\Http\Requests\RotateApiKeyRequest;
use App\Core\Api\Http\Requests\StoreApiKeyRequest;
use App\Core\Api\Http\Requests\UpdateApiKeyRequest;
use App\Core\Api\Http\Resources\ApiKeyResource;
use App\Core\Api\Models\ApiKey;
use App\Core\Rbac\ScopeNames;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * API-02, API-03: a tenant's API keys (Settings, API keys). People only,
 * with `core.api_key.manage` at tenant scope; a key never reaches these
 * routes (not public, ADR 020). The plain key is in the create and rotate
 * answers only (`secret`), shown once.
 */
class ApiKeyController
{
    public function __construct(
        private readonly ApiKeys $keys,
        private readonly ScopeNames $scopeNames,
        private readonly ApiUsage $usage,
    ) {}

    public function index(ListApiKeysRequest $request): AnonymousResourceCollection
    {
        $now = now();
        $query = ApiKey::query()->with(['assignment.role', 'creator']);

        match ($request->validated('status', 'all')) {
            'active' => $query->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now)),
            'expired' => $query->whereNull('revoked_at')->where('expires_at', '<=', $now),
            'revoked' => $query->whereNotNull('revoked_at'),
            default => null,
        };

        $page = $query->orderByDesc('created_at')->orderBy('id')->paginate($request->perPage())->withQueryString();
        $this->scopeNames->attach($page->getCollection()->pluck('assignment')->filter());

        return ApiKeyResource::collection($page)->additional(['meta' => [
            'mode' => Tenant::query()->whereKey(app(TenantContext::class)->require())->value('is_sandbox') ? 'test' : 'live',
        ]]);
    }

    /** The scopes a key may be given (`module.resource.read|write`), for the picker. */
    public function scopes(ApiKeyRequest $request, ApiScopes $scopes): JsonResponse
    {
        return new JsonResponse(['data' => $scopes->grouped()]);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        ['key' => $key, 'plain' => $plain] = $this->keys->create($request->user(), $request->validated());

        return $this->withSecret($key, $plain, 201);
    }

    public function show(ApiKeyRequest $request, ApiKey $apiKey): ApiKeyResource
    {
        return $this->resource($apiKey);
    }

    public function update(UpdateApiKeyRequest $request, ApiKey $apiKey): ApiKeyResource
    {
        return $this->resource($this->keys->update($apiKey, $request->validated()));
    }

    public function rotate(RotateApiKeyRequest $request, ApiKey $apiKey): JsonResponse
    {
        ['key' => $key, 'plain' => $plain] = $this->keys->rotate($apiKey, $request->overlapHours());

        return $this->withSecret($key, $plain, 200);
    }

    public function revoke(RevokeApiKeyRequest $request, ApiKey $apiKey): ApiKeyResource
    {
        return $this->resource($this->keys->revoke($request->user(), $apiKey));
    }

    /** API-03: requests per day, every key of the tenant or one ($apiKey). */
    public function usage(ApiKeyUsageRequest $request, ?ApiKey $apiKey = null): JsonResponse
    {
        $series = $this->usage->perDay($request->days(), $apiKey?->id);

        return new JsonResponse(['data' => $series, 'meta' => ['total' => array_sum(array_column($series, 'requests'))]]);
    }

    private function resource(ApiKey $key): ApiKeyResource
    {
        $key->load(['assignment.role', 'creator']);
        $this->scopeNames->attach(array_filter([$key->assignment]));

        return new ApiKeyResource($key);
    }

    private function withSecret(ApiKey $key, string $plain, int $status): JsonResponse
    {
        // Shown once: never cached on the way.
        return $this->resource($key)->additional(['secret' => $plain])->response()->setStatusCode($status)
            ->header('Cache-Control', 'no-store');
    }
}
