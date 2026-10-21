<?php

namespace App\Core\Sso\Saml;

use App\Core\Audit\Auditor;
use App\Core\Http\ApiException;
use App\Core\Sso\Models\SsoConnection;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * AUTH-11, ADR 023: the daily re-read of SAML metadata fetched by URL, in
 * the current tenant. A signing certificate the IdP rotated in is kept as
 * the second slot next to the current one, so sign-in keeps working across
 * the IdP's rollover; the connection stays enabled and its links stay (the
 * same IdP). Metadata naming another entity id is never followed. Audited
 * as `core.sso.metadata_refresh` or `core.sso.metadata_refresh_failed`.
 */
class SamlMetadataRefresh
{
    public function __construct(
        private readonly SamlMetadata $metadata,
        private readonly Auditor $auditor,
    ) {}

    /** @return int connections looked at */
    public function runDue(): int
    {
        $connections = SsoConnection::query()->where('provider', SsoConnection::SAML)->whereNull('archived_at')
            ->whereRaw("coalesce(settings->>'metadata_url', '') <> ''")->orderBy('created_at')->get();

        foreach ($connections as $connection) {
            $this->refresh($connection);
        }

        return $connections->count();
    }

    public function refresh(SsoConnection $connection): void
    {
        try {
            $fetched = $this->metadata->fromUrl((string) $connection->setting('metadata_url'));
        } catch (ApiException $e) {
            $this->failed($connection, $e->errorCode);

            return;
        }

        if ($fetched['idp_entity_id'] !== $connection->setting('idp_entity_id')) {
            $this->failed($connection, 'entity_changed');

            return;
        }

        $current = array_values((array) $connection->setting('idp_certificates'));
        $key = fn (string $pem) => preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem);
        $known = array_map($key, $current);
        $rotated = array_values(array_filter($fetched['idp_certificates'], fn (string $pem) => ! in_array($key($pem), $known, true)));

        if ($rotated === []) {
            return;
        }

        DB::connection(TenantContext::CONNECTION)->transaction(function () use ($connection, $current, $rotated) {
            $connection = SsoConnection::query()->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            $after = array_values(array_filter([$current[0] ?? null, $rotated[0]]));
            $connection->forceFill(['settings' => [...$connection->settings, 'idp_certificates' => $after]])->save();
            $this->auditor->record('core.sso.metadata_refresh', $connection, ['idp_certificates' => $current], ['idp_certificates' => $after]);
        });
    }

    private function failed(SsoConnection $connection, string $reason): void
    {
        $this->auditor->record('core.sso.metadata_refresh_failed', $connection, null, ['reason' => $reason]);
    }
}
