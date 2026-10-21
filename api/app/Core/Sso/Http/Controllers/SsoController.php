<?php

namespace App\Core\Sso\Http\Controllers;

use App\Core\Sso\Domains\SsoDomains;
use App\Core\Sso\Http\Requests\SsoManageRequest;
use App\Core\Sso\Http\Requests\UpdateSsoSettingsRequest;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Sso\Models\SsoDomain;
use App\Core\Sso\SsoSettings;
use Illuminate\Http\JsonResponse;

/**
 * AUTH-11, ADR 023: Settings, Security, Single sign-on (`core.sso.manage`,
 * tenant scope): the settings, connections (secrets never shown) and email
 * domains with the TXT record each needs.
 */
class SsoController
{
    public function show(SsoManageRequest $request, SsoSettings $settings): JsonResponse
    {
        return response()->json(['data' => $this->overview($settings)]);
    }

    public function update(UpdateSsoSettingsRequest $request, SsoSettings $settings): JsonResponse
    {
        $settings->update($request->user(), $request->validated());

        return response()->json(['data' => $this->overview($settings)]);
    }

    /** @return array<string, mixed> */
    private function overview(SsoSettings $settings): array
    {
        $values = $settings->values();
        unset($values['jit_set_by']);

        return [
            'settings' => $values,
            'oidc_redirect_uri' => SsoConnection::oidcRedirectUri(),
            'connections' => SsoConnection::query()->whereNull('archived_at')->orderBy('name')->get()
                ->map(fn (SsoConnection $c) => self::connection($c))->values(),
            'domains' => SsoDomain::query()->whereNull('archived_at')->orderBy('domain')->get()
                ->map(fn (SsoDomain $d) => self::domain($d))->values(),
        ];
    }

    /** @return array<string, mixed> */
    public static function connection(SsoConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider,
            'name' => $connection->name,
            'settings' => $connection->settings,
            'has_secret' => $connection->client_secret !== null,
            'enabled' => (bool) $connection->enabled,
            'tested_at' => $connection->tested_at?->toIso8601String(),
            'last_test' => $connection->last_test,
            'sp' => $connection->isOidc()
                ? ['redirect_uri' => SsoConnection::oidcRedirectUri()]
                : ['entity_id' => $connection->spEntityId(), 'acs_url' => $connection->acsUrl()],
            'created_at' => $connection->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function domain(SsoDomain $domain): array
    {
        return [
            'id' => $domain->id,
            'domain' => $domain->domain,
            'status' => $domain->status,
            'record' => [
                'type' => 'TXT',
                'name' => SsoDomains::recordName($domain),
                'value' => SsoDomains::recordValue($domain),
            ],
            'failure' => $domain->failure,
            'checked_at' => $domain->checked_at?->toIso8601String(),
            'verified_at' => $domain->verified_at?->toIso8601String(),
            'created_at' => $domain->created_at?->toIso8601String(),
        ];
    }
}
