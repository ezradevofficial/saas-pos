<?php

namespace Tests\Concerns;

use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Rbac\Console\SyncPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Marketplace apps for tests (MKT-01..MKT-04, ADR 024).
 *
 * Staff review runs on the platform connection, outside the test's
 * transaction, so an app a tenant installs is made listed and committed
 * here, on the schema owner's connection (as a platform-developed app that
 * is not a connector), and removed again after the test. The OAuth helpers
 * drive the authorization code flow with PKCE (S256) through the API.
 */
trait BuildsMarketplace
{
    public const REDIRECT = 'https://app.example.com/oauth/callback';

    public const WEBHOOK = 'https://hooks.example.com/app';

    /**
     * A listed app with a confidential client.
     *
     * @param  array<string, mixed>  $version  overrides of the listed version
     * @return array{app_id: string, client_id: string, client_secret: string, version_id: string, slug: string, webhook_secret: string}
     */
    protected function listedApp(array $version = [], ?string $secret = 'client-secret-for-tests-0123456789abcdef', bool $listed = true): array
    {
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $ids = ['app_id' => (string) Str::uuid7(), 'client_id' => (string) Str::uuid7(), 'version_id' => (string) Str::uuid7(), 'slug' => 'test-app-'.Str::lower(Str::random(8))];
        $now = now();
        $ids['webhook_secret'] = 'whsec_'.base64_encode(random_bytes(32));

        $owner->transaction(function () use ($owner, $ids, $version, $secret, $now, $listed) {
            $owner->table('oauth_clients')->insert([
                'id' => $ids['client_id'], 'name' => 'Test app', 'secret' => $secret === null ? null : Hash::make($secret),
                'redirect_uris' => json_encode([self::REDIRECT]), 'grant_types' => json_encode(['authorization_code', 'refresh_token']),
                'revoked' => false, 'app_id' => $ids['app_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_apps')->insert([
                'id' => $ids['app_id'], 'developer_id' => FirstParty::DEVELOPER, 'slug' => $ids['slug'], 'status' => 'active',
                'client_id' => $ids['client_id'], 'webhook_secret' => encrypt($ids['webhook_secret'], false),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_versions')->insert([
                'id' => $ids['version_id'], 'app_id' => $ids['app_id'], 'version' => 1, 'status' => 'draft',
                'name' => $version['name'] ?? 'Stock sync', 'tagline' => 'Keeps stock in step', 'description' => str_repeat('Syncs items and customers. ', 3),
                'category' => 'productivity', 'redirect_uris' => json_encode([self::REDIRECT]),
                'scopes' => json_encode($version['scopes'] ?? ['core.item.read', 'core.party.write']),
                'webhook_url' => $version['webhook_url'] ?? null,
                'webhook_events' => json_encode($version['webhook_events'] ?? []),
                'pricing' => json_encode($version['pricing'] ?? ['model' => 'free']),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_versions')->where('id', $ids['version_id'])->update(['status' => 'submitted', 'submitted_at' => $now]);

            if ($listed) {
                $owner->table('marketplace_app_versions')->where('id', $ids['version_id'])->update(['status' => 'approved', 'reviewed_at' => $now]);
                $owner->table('marketplace_apps')->where('id', $ids['app_id'])->update(['listed_version_id' => $ids['version_id']]);
            }
        });

        $this->forgetMarketplaceApp($ids['app_id'], $ids['client_id']);

        return [...$ids, 'client_secret' => (string) $secret];
    }

    /** A further approved version, listed (a scope change waits for each tenant's consent). */
    protected function listNewVersion(string $appId, array $version): string
    {
        $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
        $id = (string) Str::uuid7();
        $now = now();
        $latest = $owner->table('marketplace_app_versions')->where('app_id', $appId)->orderByDesc('version')->first();

        $owner->transaction(function () use ($owner, $id, $appId, $version, $latest, $now) {
            $owner->table('marketplace_app_versions')->insert([
                ...array_diff_key((array) $latest, array_flip(['id', 'version', 'status', 'submitted_at', 'reviewed_at', 'created_at', 'updated_at'])),
                'id' => $id, 'version' => $latest->version + 1, 'status' => 'draft',
                ...array_map(fn ($value) => is_array($value) ? json_encode($value) : $value, $version),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $owner->table('marketplace_app_versions')->where('id', $id)->update(['status' => 'submitted']);
            $owner->table('marketplace_app_versions')->where('id', $id)->update(['status' => 'approved']);
            $owner->table('marketplace_apps')->where('id', $appId)->update(['listed_version_id' => $id]);
        });

        return $id;
    }

    /** Remove a committed app (and its versions and client) after the test, once its transaction has rolled back. */
    protected function forgetMarketplaceApp(string $appId, string $clientId): void
    {
        $this->beforeApplicationDestroyed(function () use ($appId, $clientId) {
            $owner = DB::connection(SyncPermissions::OWNER_CONNECTION);
            $owner->transaction(function () use ($owner, $appId, $clientId) {
                $owner->statement('alter table marketplace_app_versions disable trigger user');
                $owner->statement('alter table marketplace_apps disable trigger user');
                $owner->table('marketplace_apps')->where('id', $appId)->update(['listed_version_id' => null]);
                $owner->table('marketplace_app_versions')->where('app_id', $appId)->delete();
                $owner->table('marketplace_apps')->where('id', $appId)->delete();
                $owner->table('oauth_clients')->where('id', $clientId)->delete();
                $owner->statement('alter table marketplace_app_versions enable trigger user');
                $owner->statement('alter table marketplace_apps enable trigger user');
            });
        });
    }

    /** @return array{verifier: string, challenge: string} a PKCE pair (S256) */
    protected function pkce(): array
    {
        $verifier = Str::random(64);

        return ['verifier' => $verifier, 'challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')];
    }

    /** @return array<string, string> */
    protected function authorizeQuery(string $clientId, string $challenge, array $extra = []): array
    {
        return array_merge([
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'response_type' => 'code',
            'state' => 'state-123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $extra);
    }

    /** Approve the app as $role at $scope; the code from the redirect. */
    protected function approve(string $clientId, string $challenge, array $headers, array $as = ['role' => null, 'scope_type' => 'tenant', 'scope_id' => null]): TestResponse
    {
        return $this->postJson('/api/v1/oauth/authorize', [
            ...$this->authorizeQuery($clientId, $challenge),
            'decision' => 'approve',
            'role_id' => $as['role'],
            'scope_type' => $as['scope_type'],
            'scope_id' => $as['scope_id'],
        ], $headers);
    }

    protected static function codeFrom(string $redirect): string
    {
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

        return (string) ($query['code'] ?? '');
    }

    protected function exchange(string $code, string $verifier, array $app): TestResponse
    {
        return $this->post('/api/v1/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'redirect_uri' => self::REDIRECT,
            'code' => $code,
            'code_verifier' => $verifier,
        ], ['Accept' => 'application/json']);
    }

    protected function refresh(string $refreshToken, array $app): TestResponse
    {
        return $this->post('/api/v1/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'],
            'refresh_token' => $refreshToken,
        ], ['Accept' => 'application/json']);
    }
}
