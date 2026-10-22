<?php

// Loaded by routes/api.php (prefix /api/v1). MKT-01..MKT-04, ADM-05, API-02 (OAuth):
// the marketplace (docs/adr/024-marketplace.md).

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Marketplace\Http\Controllers\AuthorizeController;
use App\Core\Marketplace\Http\Controllers\DeveloperAppController;
use App\Core\Marketplace\Http\Controllers\DeveloperController;
use App\Core\Marketplace\Http\Controllers\InstallationController;
use App\Core\Marketplace\Http\Controllers\MarketplaceController;
use App\Core\Marketplace\Http\Controllers\PlatformMarketplaceController;
use App\Core\Marketplace\Http\Controllers\PlatformPayoutController;
use App\Core\Marketplace\Http\Controllers\TokenController;
use App\Core\Marketplace\MarketplaceServiceProvider;
use App\Core\Marketplace\Models\AppInstallation;
use App\Core\Marketplace\Models\MarketplaceApp;
use App\Core\Platform\Http\Middleware\EnsurePlatformAccess;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;

foreach (['marketplace_app', 'developer_app', 'app_installation'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

// A listed app (global; row-level security shows a tenant listed apps and its own).
Route::model('marketplace_app', MarketplaceApp::class);
// The developer portal reaches only this tenant's own apps.
Route::bind('developer_app', fn (string $value) => MarketplaceApp::query()->where('developer_tenant_id', app(TenantContext::class)->require())->findOrFail($value));
Route::model('app_installation', AppInstallation::class);

// API-02 (OAuth): the token endpoint. The client authenticates (its secret,
// when confidential, and the PKCE verifier); codes and refresh tokens are
// looked up by hash through a security-definer function; rate-limited per IP.
Route::post('oauth/token', [TokenController::class, 'issue'])->middleware('throttle:'.MarketplaceServiceProvider::TOKEN_LIMITER);

// MKT-01: logos of approved versions (public: the listing and consent screen show them).
Route::get('marketplace/logos/{version}', [MarketplaceController::class, 'logo'])->whereUuid('version')->middleware('throttle:media');

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class])->group(function () {
    // MKT-01, MKT-04: browse (core.marketplace.view).
    Route::get('marketplace/apps', [MarketplaceController::class, 'index']);
    Route::get('marketplace/apps/{marketplace_app}', [MarketplaceController::class, 'show']);
    Route::get('marketplace/connectors', [MarketplaceController::class, 'connectors']);

    // MKT-02: the consent screen of the authorization code flow (PKCE S256).
    Route::get('oauth/authorize', [AuthorizeController::class, 'show']);
    Route::post('oauth/authorize', [AuthorizeController::class, 'decide'])->middleware('throttle:'.MarketplaceServiceProvider::AUTHORIZE_LIMITER);

    // MKT-02: installed apps (view; consent and uninstall: core.marketplace.install).
    Route::get('apps/installed', [InstallationController::class, 'index']);
    Route::get('apps/installed/{app_installation}', [InstallationController::class, 'show']);
    Route::post('apps/installed/{app_installation}/consent', [InstallationController::class, 'consent']);
    Route::post('apps/installed/{app_installation}/uninstall', [InstallationController::class, 'uninstall']);

    // MKT-01: the developer portal (core.developer.manage).
    Route::get('developer', [DeveloperController::class, 'show']);
    Route::put('developer', [DeveloperController::class, 'save']);
    Route::get('developer/apps', [DeveloperAppController::class, 'index']);
    Route::post('developer/apps', [DeveloperAppController::class, 'store']);
    Route::get('developer/apps/{developer_app}', [DeveloperAppController::class, 'show']);
    Route::patch('developer/apps/{developer_app}/draft', [DeveloperAppController::class, 'updateDraft']);
    Route::get('developer/apps/{developer_app}/logo', [DeveloperAppController::class, 'logo']);
    Route::post('developer/apps/{developer_app}/logo', [DeveloperAppController::class, 'storeLogo']);
    Route::post('developer/apps/{developer_app}/submit', [DeveloperAppController::class, 'submit']);
    Route::post('developer/apps/{developer_app}/withdraw', [DeveloperAppController::class, 'withdraw']);
    Route::post('developer/apps/{developer_app}/versions', [DeveloperAppController::class, 'newVersion']);
    Route::post('developer/apps/{developer_app}/client-secret', [DeveloperAppController::class, 'rotateClientSecret']);
    Route::post('developer/apps/{developer_app}/webhook-secret', [DeveloperAppController::class, 'rotateWebhookSecret']);
    Route::post('developer/apps/{developer_app}/archive', [DeveloperAppController::class, 'archive']);
    Route::post('developer/apps/{developer_app}/restore', [DeveloperAppController::class, 'restore']);
});

// ADM-05, MKT-03: review and payouts (platform tokens only).
Route::middleware(['auth:platform', EnsurePlatformAccess::class])->prefix('platform/marketplace')->group(function () {
    Route::get('apps', [PlatformMarketplaceController::class, 'index']);
    Route::get('apps/{app}', [PlatformMarketplaceController::class, 'show'])->whereUuid('app');
    Route::post('versions/{version}/approve', [PlatformMarketplaceController::class, 'approve'])->whereUuid('version');
    Route::post('versions/{version}/reject', [PlatformMarketplaceController::class, 'reject'])->whereUuid('version');
    Route::post('apps/{app}/suspend', [PlatformMarketplaceController::class, 'suspend'])->whereUuid('app');
    Route::post('apps/{app}/reinstate', [PlatformMarketplaceController::class, 'reinstate'])->whereUuid('app');

    Route::get('payouts', [PlatformPayoutController::class, 'index']);
    Route::get('developers/{developer}/ledger', [PlatformPayoutController::class, 'ledger'])->whereUuid('developer');
    Route::post('developers/{developer}/payouts', [PlatformPayoutController::class, 'store'])->whereUuid('developer');
});
