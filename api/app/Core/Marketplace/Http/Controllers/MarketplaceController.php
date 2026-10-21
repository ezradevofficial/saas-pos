<?php

namespace App\Core\Marketplace\Http\Controllers;

use App\Core\Billing\Subscriptions\Subscriptions;
use App\Core\Marketplace\Connectors\Connectors;
use App\Core\Marketplace\DeveloperApps;
use App\Core\Marketplace\Http\MarketplacePresenter;
use App\Core\Marketplace\Http\Requests\ListMarketplaceAppsRequest;
use App\Core\Marketplace\Http\Requests\ViewMarketplaceRequest;
use App\Core\Marketplace\Installations;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Marketplace\Models\MarketplaceAppVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * MKT-01, MKT-04: the marketplace a tenant browses: listed apps (an
 * approved version, not suspended or archived), an app's page with what it
 * asks for in plain words, the first-party connectors' state, and the
 * public logos of approved versions.
 */
class MarketplaceController
{
    public function __construct(
        private readonly MarketplacePresenter $presenter,
        private readonly Subscriptions $subscriptions,
        private readonly Installations $installations,
    ) {}

    public function index(ListMarketplaceAppsRequest $request): JsonResponse
    {
        $query = MarketplaceApp::query()->where('status', MarketplaceApp::ACTIVE)->whereNotNull('listed_version_id')->with(['listedVersion', 'developer', 'client']);
        $search = trim((string) $request->validated('search', ''));

        if ($search !== '') {
            $query->whereHas('listedVersion', fn ($q) => $q->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'));
        }

        if ($request->filled('category')) {
            $query->whereHas('listedVersion', fn ($q) => $q->where('category', $request->validated('category')));
        }

        $installed = AppInstallation::query()->where('status', AppInstallation::ACTIVE)->pluck('id', 'app_id');
        $currency = $this->subscriptions->current()?->currency;
        $apps = $query->get()->sortBy(fn (MarketplaceApp $app) => mb_strtolower((string) $app->listedVersion?->name))->values();

        return new JsonResponse(['data' => $apps->map(fn (MarketplaceApp $app) => $this->presenter->listing(
            $app, $currency, isset($installed[$app->id]) ? new AppInstallation : null,
        ))->all()]);
    }

    public function show(ViewMarketplaceRequest $request, MarketplaceApp $marketplaceApp): JsonResponse
    {
        abort_if($marketplaceApp->listed_version_id === null, 404);
        $marketplaceApp->load(['listedVersion', 'developer', 'client']);
        $installation = $this->installations->activeFor($marketplaceApp)?->load(['app.listedVersion', 'version', 'installer']);

        return new JsonResponse(['data' => $this->presenter->detail($marketplaceApp, $this->subscriptions->current()?->currency, $installation)]);
    }

    public function connectors(ViewMarketplaceRequest $request, Connectors $connectors): JsonResponse
    {
        return new JsonResponse(['data' => $connectors->status()]);
    }

    /** The logo of an approved version: public (the listing and consent screen show it), cached a day. */
    public function logo(string $version): Response
    {
        $row = MarketplaceAppVersion::query()->where('status', MarketplaceAppVersion::APPROVED)->find($version);
        abort_if($row === null || $row->logo_path === null || ! Storage::disk(DeveloperApps::disk())->exists($row->logo_path), 404);

        return Storage::disk(DeveloperApps::disk())->response($row->logo_path, null, [
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }
}
