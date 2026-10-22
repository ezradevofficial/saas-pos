<?php

namespace App\Core\Marketplace\Connectors;

use App\Core\Marketplace\AppEndpoints;
use App\Core\Marketplace\Connectors\Courier\Couriers;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Notifications\Channels;
use App\Core\Notifications\Drivers\ChannelDrivers;
use App\Core\Rbac\ModuleRegistry;

/**
 * MKT-04: whether each first-party connector is ready, and what is still
 * missing. Missing items are owner inputs (the connector service's OAuth
 * set-up, a courier and its credentials, the SMS and WhatsApp providers);
 * they are named, never filled with invented values.
 *
 * - `courier`: the connector service is set up and a courier driver is configured.
 * - `messaging`: set up, and the notifier has an SMS or WhatsApp driver.
 * - `bi`: set up; it reads the public list endpoints as CSV feeds.
 * - `biometric`: set up; terminals send punches to the HR device API (HR-24),
 *   so HR must be active for the tenant.
 *
 * A connector that is not available yet (FirstParty, Phase 8 review L6)
 * names `public_api` first: its scopes have no public route to work with.
 */
class Connectors
{
    public function __construct(
        private readonly Couriers $couriers,
        private readonly ChannelDrivers $channels,
        private readonly ModuleRegistry $modules,
    ) {}

    /** @return list<array{key: string, app_id: string, slug: string, available: bool, configured: bool, missing: list<string>, details: array<string, mixed>}> */
    public function status(): array
    {
        $apps = MarketplaceApp::query()->with('client')->whereIn('id', array_values(FirstParty::appIds()))->get()->keyBy('id');
        $statuses = [];

        foreach (FirstParty::CONNECTORS as $key => $connector) {
            $app = $apps->get($connector['app']);
            $missing = [
                ...($connector['available'] ? [] : ['public_api']),
                ...($app !== null && AppEndpoints::connectorConfigured($app) ? [] : ['connector_service']),
            ];
            $details = [];

            switch ($key) {
                case 'courier':
                    $details['driver'] = $this->couriers->driver()->key();

                    if (! $this->couriers->driver()->configured()) {
                        $missing[] = 'courier_provider';
                    }
                    break;
                case 'messaging':
                    $details['sms'] = $this->channels->available(Channels::SMS);
                    $details['whatsapp'] = $this->channels->available(Channels::WHATSAPP);

                    if (! $details['sms'] && ! $details['whatsapp']) {
                        $missing[] = 'message_provider';
                    }
                    break;
                case 'bi':
                    $details['format'] = 'csv';
                    break;
                case 'biometric':
                    $details['hr_active'] = $this->modules->isActive('hr');
                    break;
            }

            $statuses[] = [
                'key' => $key,
                'app_id' => $connector['app'],
                'slug' => $connector['slug'],
                'available' => $connector['available'],
                'configured' => $missing === [],
                'missing' => $missing,
                'details' => $details,
            ];
        }

        return $statuses;
    }
}
