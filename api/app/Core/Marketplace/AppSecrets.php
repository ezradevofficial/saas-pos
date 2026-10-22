<?php

namespace App\Core\Marketplace;

use App\Core\Http\ApiException;
use App\Core\Platform\PlatformDatabase;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * MKT-01, MKT-02 (security review, ADR 024): an app's secrets live in
 * `marketplace_app_secrets`, which no runtime role can read. They are
 * reached only through security-definer functions (ADR 002):
 *
 * - `oauth_client_secret_ok(client, sha256)`: whether a presented client
 *   secret matches (the digest never leaves the database);
 * - `app_install_webhook_key(install)`: an install's own signing key,
 *   `HMAC-SHA256(app webhook key, 'install:' || install id)`, only for an
 *   active install of the session's tenant. The developer derives the same
 *   key from the app's webhook secret and the install id in the URL;
 * - `marketplace_store_app_secrets(app, sha256, key)`: written only by the
 *   app's developer tenant, or the platform role for first-party apps;
 * - `marketplace_developer_active(developer)`: a suspended developer, or a
 *   closed developer tenant, stops its apps serving.
 */
class AppSecrets
{
    /** @return array{raw: string, display: string} a new webhook key and how the developer sees it (`whsec_` + base64) */
    public static function newWebhookKey(): array
    {
        $raw = random_bytes(32);

        return ['raw' => $raw, 'display' => 'whsec_'.base64_encode($raw)];
    }

    /** The key an install signs with, from the app's webhook secret as the developer holds it. */
    public static function deriveInstallKey(string $appWebhookSecret, string $installationId): string
    {
        $raw = base64_decode(substr($appWebhookSecret, strlen('whsec_')), true) ?: '';

        return 'whsec_'.base64_encode(hash_hmac('sha256', 'install:'.$installationId, $raw, true));
    }

    /**
     * Set an app's client secret and/or webhook key (null keeps it).
     * Refused (403) unless the session's tenant is the app's developer, or
     * the platform role for a first-party app.
     */
    public function store(string $appId, ?string $clientSecret, ?string $webhookKey, ?Connection $connection = null): void
    {
        $connection ??= DB::connection(TenantContext::CONNECTION);
        $changed = (int) $connection->selectOne(
            'select public.marketplace_store_app_secrets(?, ?, case when ?::text is null then null else decode(?, \'hex\') end) as n',
            [$appId, $clientSecret === null ? null : hash('sha256', $clientSecret), $webhookKey === null ? null : bin2hex($webhookKey), $webhookKey === null ? null : bin2hex($webhookKey)],
        )?->n;

        if ($changed < 1) {
            throw new ApiException(403, 'forbidden', __('marketplace.errors.not_your_app'));
        }
    }

    public function clientSecretOk(string $clientId, string $secret): bool
    {
        return (bool) DB::connection(TenantContext::CONNECTION)
            ->selectOne('select public.oauth_client_secret_ok(?, ?) as ok', [$clientId, hash('sha256', $secret)])?->ok;
    }

    /** The install's signing key, or null when it is not an active install of the session's tenant. */
    public function installKey(string $installationId): ?string
    {
        $key = DB::connection(TenantContext::CONNECTION)->selectOne('select public.app_install_webhook_key(?) as k', [$installationId])?->k;

        return is_string($key) ? $key : null;
    }

    /** Whether the developer may serve: not suspended, its tenant not closed (asked each time, so a suspension acts at once). */
    public function developerActive(string $developerId): bool
    {
        return (bool) DB::connection(TenantContext::CONNECTION)
            ->selectOne('select public.marketplace_developer_active(?) as ok', [$developerId])?->ok;
    }

    /**
     * The tenants with an active install of the app, read as the platform
     * role (the function answers only it and the app's developer tenant).
     *
     * @return list<string>
     */
    public function installedTenants(string $appId, ?Connection $connection = null): array
    {
        $connection ??= PlatformDatabase::connection();

        return array_map(fn ($row) => (string) $row->id, $connection->select('select t as id from public.app_tenants_with_app_installed(?) t', [$appId]));
    }
}
