<?php

namespace App\Core\Marketplace\Http;

use App\Core\Marketplace\AppEndpoints;
use App\Core\Marketplace\AppPricing;
use App\Core\Marketplace\Installations;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use App\Core\Marketplace\ScopeDescriptions;
use App\Core\Rbac\Models\RoleAssignment;
use App\Core\Rbac\ScopeNames;

/**
 * MKT-01, MKT-02: what the web app shows of apps, versions and installs.
 * Never a secret, a secret's hash or a webhook URL's path to a tenant;
 * the developer sees its own app's webhook URL.
 */
class MarketplacePresenter
{
    public function __construct(
        private readonly ScopeDescriptions $scopes,
        private readonly ScopeNames $scopeNames,
    ) {}

    /** @return array<string, mixed> an app as the listing shows it */
    public function listing(MarketplaceApp $app, ?string $currency, ?AppInstallation $installation = null): array
    {
        $version = $app->listedVersion;

        return [
            'id' => $app->id,
            'slug' => $app->slug,
            'status' => $app->status,
            'name' => $version?->name,
            'tagline' => $version?->tagline,
            'category' => $version?->category,
            'logo_url' => $version === null ? null : self::logoUrl($version),
            'developer' => [
                'name' => $app->developer?->name,
                'website' => $app->developer?->website,
                'first_party' => (bool) $app->developer?->first_party,
            ],
            'pricing' => $version === null ? null : self::pricing($version->pricing(), $currency),
            'installed' => $installation !== null,
            'configured' => AppEndpoints::installable($app),
        ];
    }

    /** @return array<string, mixed> an app's page: what it asks for and what this tenant consented to */
    public function detail(MarketplaceApp $app, ?string $currency, ?AppInstallation $installation): array
    {
        $version = $app->listedVersion;

        return [
            ...$this->listing($app, $currency, $installation),
            'description' => $version?->description,
            'website' => $version?->website,
            'support_email' => $version?->support_email,
            'privacy_url' => $version?->privacy_url,
            'version' => $version?->version,
            'scopes' => $version === null ? [] : $this->scopes->describe($version->scopeList()),
            'webhook_events' => $version?->eventList() ?? [],
            'client_id' => $app->client_id,
            'installation' => $installation === null ? null : $this->installation($installation),
        ];
    }

    /** @return array<string, mixed> */
    public function installation(AppInstallation $installation): array
    {
        $assignment = RoleAssignment::query()->with('role')->where('user_id', $installation->principal_id)->first();

        if ($assignment !== null) {
            $this->scopeNames->attach([$assignment]);
        }

        $listed = $installation->app?->listedVersion;

        return [
            'id' => $installation->id,
            'app_id' => $installation->app_id,
            'status' => $installation->status,
            'name' => $installation->version?->name,
            'logo_url' => $installation->version === null ? null : self::logoUrl($installation->version),
            'app_status' => $installation->app?->status,
            'version' => $installation->version?->version,
            'listed_version' => $listed?->version,
            'needs_consent' => $installation->isActive() && Installations::needsConsent($installation, $listed),
            'scopes' => $this->scopes->describe($installation->scopeList()),
            'new_scopes' => $listed === null ? [] : $this->scopes->describe(array_values(array_diff($listed->scopeList(), $installation->scopeList()))),
            'webhook_events' => $installation->eventList(),
            'price' => $installation->price,
            'role' => $assignment?->role === null ? null : ['id' => $assignment->role->id, 'name' => $assignment->role->name],
            'scope' => $assignment === null ? null : ['type' => $assignment->scope_type, 'id' => $assignment->scope_id, 'name' => $assignment->scopeName],
            'api_key_id' => $installation->api_key_id,
            'installed_at' => $installation->installed_at?->toIso8601String(),
            'installed_by' => $installation->installer?->name,
            'consented_at' => $installation->consented_at?->toIso8601String(),
            'uninstalled_at' => $installation->uninstalled_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> a version as its developer and staff see it */
    public function version(MarketplaceAppVersion $version): array
    {
        return [
            'id' => $version->id,
            'version' => $version->version,
            'status' => $version->status,
            ...$version->only(['name', 'tagline', 'description', 'category', 'website', 'support_email', 'privacy_url', 'webhook_url']),
            'logo_url' => self::logoUrl($version),
            'has_logo' => $version->logo_path !== null,
            'redirect_uris' => $version->redirectList(),
            'scopes' => $version->scopeList(),
            'webhook_events' => $version->eventList(),
            'pricing' => $version->pricing()->toArray(),
            'submitted_at' => $version->submitted_at?->toIso8601String(),
            'reviewed_at' => $version->reviewed_at?->toIso8601String(),
            'review_note' => $version->review_note,
        ];
    }

    /** @return array<string, mixed> a developer's own app */
    public function developerApp(MarketplaceApp $app): array
    {
        $versions = $app->versions->sortByDesc('version')->values();

        return [
            'id' => $app->id,
            'slug' => $app->slug,
            'status' => $app->status,
            'client_id' => $app->client_id,
            'listed_version_id' => $app->listed_version_id,
            'suspended_reason' => $app->suspended_reason,
            'name' => $versions->first()?->name,
            'versions' => $versions->map(fn (MarketplaceAppVersion $version) => $this->version($version))->all(),
            'created_at' => $app->created_at?->toIso8601String(),
        ];
    }

    /** Approved versions' logos are public; a draft's is read by its developer only (developer/apps/{id}/logo). */
    public static function logoUrl(MarketplaceAppVersion $version): ?string
    {
        if ($version->logo_path === null) {
            return null;
        }

        return $version->status === MarketplaceAppVersion::APPROVED
            ? url("/api/v1/marketplace/logos/{$version->id}")
            : url("/api/v1/developer/apps/{$version->app_id}/logo");
    }

    /** @return array<string, mixed> the price in the tenant's billing currency (or every currency, for staff) */
    public static function pricing(AppPricing $pricing, ?string $currency): array
    {
        if ($pricing->isFree()) {
            return ['model' => AppPricing::FREE];
        }

        $snapshot = $currency === null ? null : $pricing->snapshot($currency);

        return [
            'model' => AppPricing::PAID,
            'currency' => $snapshot['currency'] ?? null,
            'monthly_minor' => $snapshot['monthly_minor'] ?? null,
            'yearly_minor' => $snapshot['yearly_minor'] ?? null,
            'available' => $snapshot !== null,
            'prices' => $pricing->toArray(),
        ];
    }
}
