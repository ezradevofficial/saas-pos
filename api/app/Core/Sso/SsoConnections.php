<?php

namespace App\Core\Sso;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Identity\Models\User;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: the tenant's connections. Every change is audited as
 * `core.sso.connection_*` with the public settings before and after and
 * whether the secret changed, never the secret.
 *
 * - A changed setting or secret clears the last test; enabling needs a
 *   passing test since. A new name, secret or Workspace domain keeps an
 *   enabled connection enabled; a change to an identity-bearing setting
 *   (client id, directory, IdP entity id, certificates) repoints it at
 *   another IdP: its links are archived and it is disabled until a new
 *   test passes (ADR 023, revised).
 * - While SSO is enforced, the last enabled connection cannot be disabled
 *   or archived.
 */
class SsoConnections
{
    /** Settings each provider keeps (public values only). */
    public const SETTINGS = [
        SsoConnection::ENTRA => ['client_id', 'directory_id'],
        SsoConnection::GOOGLE => ['client_id', 'hosted_domain'],
        SsoConnection::SAML => ['idp_entity_id', 'idp_sso_url', 'idp_certificates', 'metadata_url'],
    ];

    /** Settings that name the IdP: changing one points the connection at another IdP. */
    public const IDENTITY_BEARING = ['client_id', 'directory_id', 'idp_entity_id', 'idp_certificates'];

    public function __construct(
        private readonly Auditor $auditor,
        private readonly SsoSettings $settings,
        private readonly SsoIdentities $identities,
    ) {}

    /** @param array{provider: string, name: string, settings: array<string, mixed>, client_secret?: ?string} $data */
    public function create(User $actor, array $data): SsoConnection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($actor, $data) {
            $connection = SsoConnection::create([
                'provider' => $data['provider'],
                'name' => $data['name'],
                'settings' => $this->only($data['provider'], $data['settings']),
                'client_secret' => $data['provider'] === SsoConnection::SAML ? null : ($data['client_secret'] ?? null),
                'enabled' => false,
                'created_by' => $actor->id,
            ]);

            $this->auditor->record('core.sso.connection_create', $connection, null, [
                ...$this->shown($connection),
                'secret_set' => $connection->client_secret !== null,
            ]);

            return $connection;
        });
    }

    /** @param array{name?: string, settings?: array<string, mixed>, client_secret?: ?string} $data */
    public function update(SsoConnection $connection, array $data): SsoConnection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection, $data) {
            $connection = $this->locked($connection);
            $before = $this->shown($connection);

            if (array_key_exists('name', $data)) {
                $connection->name = $data['name'];
            }

            if (array_key_exists('settings', $data)) {
                $connection->settings = [...$connection->settings, ...$this->only($connection->provider, $data['settings'])];
            }

            $secretChanged = false;

            if ($connection->isOidc() && isset($data['client_secret']) && $data['client_secret'] !== $connection->client_secret) {
                $connection->client_secret = $data['client_secret'];
                $secretChanged = true;
            }

            $settingsChanged = $connection->settings !== $before['settings'];
            $repointed = array_values(array_filter(self::IDENTITY_BEARING, fn (string $key) => ($before['settings'][$key] ?? null) !== ($connection->settings[$key] ?? null)));
            $wasEnabled = (bool) $connection->enabled;

            if ($settingsChanged || $secretChanged) {
                $connection->tested_at = null;
                $connection->last_test = null;
            }

            // ADR 023 (revised): another IdP. Its subjects mean other people, so
            // the links go, and the connection waits for a new passing test.
            if ($repointed !== [] && $wasEnabled) {
                $this->assertNotLastWhileEnforced($connection);
                $connection->enabled = false;
            }

            $connection->save();
            $after = $this->shown($connection);

            if ($after !== $before || $secretChanged) {
                $this->auditor->record('core.sso.connection_update', $connection, $before, [...$after, 'secret_changed' => $secretChanged]);
            }

            if ($repointed !== []) {
                if ($wasEnabled) {
                    $this->auditor->record('core.sso.connection_disable', $connection, ['enabled' => true], ['enabled' => false, 'reason' => 'repointed']);
                }

                $this->identities->archiveForConnection($connection, $repointed);
            }

            return $connection;
        });
    }

    public function enable(SsoConnection $connection): SsoConnection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection) {
            $connection = $this->locked($connection);

            if ($connection->enabled) {
                return $connection;
            }

            if ($connection->tested_at === null || ($connection->last_test['ok'] ?? false) !== true) {
                throw new ApiException(409, 'connection_untested', __('sso.errors.connection_untested'));
            }

            $connection->forceFill(['enabled' => true])->save();
            $this->auditor->record('core.sso.connection_enable', $connection, ['enabled' => false], ['enabled' => true]);

            return $connection;
        });
    }

    public function disable(SsoConnection $connection): SsoConnection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection) {
            $connection = $this->locked($connection);

            if (! $connection->enabled) {
                return $connection;
            }

            $this->assertNotLastWhileEnforced($connection);
            $connection->forceFill(['enabled' => false])->save();
            $this->auditor->record('core.sso.connection_disable', $connection, ['enabled' => true], ['enabled' => false]);

            return $connection;
        });
    }

    public function archive(SsoConnection $connection): SsoConnection
    {
        return DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection) {
            $connection = $this->locked($connection);

            if ($connection->archived_at !== null) {
                return $connection;
            }

            if ($connection->enabled) {
                $this->assertNotLastWhileEnforced($connection);
            }

            $connection->forceFill(['archived_at' => CarbonImmutable::now(), 'enabled' => false])->save();
            $this->auditor->record('core.sso.connection_archive', $connection, ['name' => $connection->name], ['archived' => true]);

            return $connection;
        });
    }

    /**
     * The outcome of a test sign-in (ADR 023): stored on the connection and audited.
     *
     * @param  array<string, mixed>  $result  ok, reason, email, mfa, user_id
     */
    public function recordTest(SsoConnection $connection, array $result): void
    {
        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection, $result) {
            $connection = $this->locked($connection);
            $result = [...$result, 'at' => CarbonImmutable::now()->toIso8601String()];
            $connection->forceFill(['last_test' => $result, 'tested_at' => ($result['ok'] ?? false) ? CarbonImmutable::now() : null])->save();
            $this->auditor->record('core.sso.connection_test', $connection, null, $result);
        });
    }

    /** @return array{name: string, provider: string, settings: array<string, mixed>, enabled: bool} */
    public function shown(SsoConnection $connection): array
    {
        return [
            'name' => $connection->name,
            'provider' => $connection->provider,
            'settings' => $connection->settings,
            'enabled' => (bool) $connection->enabled,
        ];
    }

    /** @param array<string, mixed> $settings */
    private function only(string $provider, array $settings): array
    {
        return array_intersect_key($settings, array_flip(self::SETTINGS[$provider] ?? []));
    }

    private function locked(SsoConnection $connection): SsoConnection
    {
        return SsoConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
    }

    private function assertNotLastWhileEnforced(SsoConnection $connection): void
    {
        if (! $this->settings->enforced()) {
            return;
        }

        $others = SsoConnection::query()->whereKeyNot($connection->id)->where('enabled', true)->whereNull('archived_at')->exists();

        if (! $others) {
            throw new ApiException(409, 'enforce_needs_connection', __('sso.errors.enforce_needs_connection'));
        }
    }
}
