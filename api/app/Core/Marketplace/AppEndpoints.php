<?php

namespace App\Core\Marketplace;

use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;

/**
 * MKT-02, MKT-04: where an app's webhooks go and whether it can be
 * installed. An app uses its approved version's webhook URL (its redirect
 * URIs are on its OAuth client, set at approval). A first-party connector
 * (MKT-04) runs as a connector service whose URLs are owner inputs
 * (`marketplace.connectors.<key>`): until its redirect URI is set up
 * (`marketplace:connector`), it is listed as not configured and cannot be
 * installed.
 */
final class AppEndpoints
{
    public static function webhookUrl(MarketplaceApp $app, MarketplaceAppVersion $version): ?string
    {
        $key = self::connectorKey($app);
        $url = $key === null ? $version->webhook_url : config("marketplace.connectors.{$key}.webhook_url");

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** Whether new installs are possible: always for a listed app; for a connector, once it is configured. */
    public static function installable(MarketplaceApp $app): bool
    {
        return self::connectorKey($app) === null || self::connectorConfigured($app);
    }

    public static function connectorConfigured(MarketplaceApp $app): bool
    {
        $client = $app->client;

        return $client !== null && ! $client->revoked && $client->redirect_uris !== [];
    }

    /** The connector key (`courier`, `messaging`, `bi`, `biometric`) of a first-party connector, or null for any other app. */
    public static function connectorKey(MarketplaceApp $app): ?string
    {
        if (! $app->isFirstParty()) {
            return null;
        }

        foreach ((array) config('marketplace.connectors') as $key => $connector) {
            if (($connector['app_slug'] ?? null) === $app->slug) {
                return (string) $key;
            }
        }

        return null;
    }
}
