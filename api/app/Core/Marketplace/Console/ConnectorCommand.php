<?php

namespace App\Core\Marketplace\Console;

use App\Core\Marketplace\AppSecrets;
use App\Core\Marketplace\Connectors\FirstParty;
use App\Core\Marketplace\Http\Requests\VersionContentRules;
use App\Core\Marketplace\Jobs\RollAppWebhookSecret;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\OAuth\OAuthClient;
use App\Core\Platform\PlatformAuditor;
use App\Core\Platform\PlatformDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * MKT-04: set up a first-party connector's connector service as an OAuth
 * client, from its owner inputs (`marketplace.connectors.<key>`:
 * redirect URI, webhook URL). Prints the client secret and the webhook
 * signing secret once, for the connector service's own configuration.
 * Runs on the platform connection (no owner credentials); audited in the
 * platform chain. `--disable` makes the connector not configured again.
 */
class ConnectorCommand extends Command
{
    protected $signature = 'marketplace:connector {connector : courier, messaging, bi or biometric} {--rotate : issue new secrets for a connector already set up} {--disable : stop new installs and tokens}';

    protected $description = 'Set up a first-party marketplace connector (MKT-04)';

    public function handle(PlatformAuditor $audit, AppSecrets $appSecrets): int
    {
        $key = (string) $this->argument('connector');
        $connector = FirstParty::CONNECTORS[$key] ?? null;

        if ($connector === null) {
            $this->error('Unknown connector. Use one of: '.implode(', ', array_keys(FirstParty::CONNECTORS)).'.');

            return self::INVALID;
        }

        $app = MarketplaceApp::on(PlatformDatabase::CONNECTION)->findOrFail($connector['app']);
        $client = OAuthClient::on(PlatformDatabase::CONNECTION)->findOrFail($app->client_id);

        if ($this->option('disable')) {
            $client->forceFill(['revoked' => true])->save();
            $audit->record('platform.marketplace.connector_disabled', $app, null, ['connector' => $key]);
            $this->info("The {$key} connector is no longer configured.");

            return self::SUCCESS;
        }

        $redirect = (string) config("marketplace.connectors.{$key}.redirect_uri");

        if ($redirect === '' || ! VersionContentRules::validRedirect($redirect)) {
            $this->error('Set the connector service\'s redirect URI first (HTTPS): CONNECTOR_'.strtoupper($key).'_REDIRECT_URI.');

            return self::FAILURE;
        }

        $fresh = $client->secret === null || $this->option('rotate');
        $secrets = [];

        if ($fresh) {
            $webhookKey = AppSecrets::newWebhookKey();
            $secrets['client_secret'] = Str::random(48);
            $secrets['webhook_secret'] = $webhookKey['display'];
            $until = CarbonImmutable::now()->addHours((int) config('marketplace.apps.webhook_rotation_overlap_hours', 24));
            // In the table no runtime role reads, through its function (the platform role, first-party apps only).
            $appSecrets->store($app->id, $secrets['client_secret'], $webhookKey['raw'], PlatformDatabase::connection());

            // Installs already made get their new derived key, each in its own tenant.
            foreach ($appSecrets->installedTenants($app->id) as $tenantId) {
                RollAppWebhookSecret::dispatch($tenantId, $app->id, $until->toIso8601String());
            }
        }

        // The client row holds only the marker of a confidential client, never the secret or its hash.
        PlatformDatabase::connection()->table('oauth_clients')->where('id', $client->id)->update(['redirect_uris' => json_encode([$redirect]), 'revoked' => false, 'secret' => OAuthClient::CONFIDENTIAL, 'updated_at' => now()]);
        $audit->record('platform.marketplace.connector_setup', $app, null, ['connector' => $key, 'redirect_uri' => $redirect, 'new_secrets' => $fresh]);

        $this->info("The {$key} connector is configured. Client id: {$client->id}");

        foreach ($secrets as $name => $value) {
            $this->line("{$name}: {$value}");
        }

        if ($secrets !== []) {
            $this->warn('These secrets are shown once. Put them in the connector service\'s configuration.');
        }

        return self::SUCCESS;
    }
}
