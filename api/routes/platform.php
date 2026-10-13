<?php

// Loaded by routes/api.php (prefix /api/v1). ADR 013, SUB-01, ONB-03.

use App\Core\Billing\Catalogue\Http\Controllers\AnnualDiscountController;
use App\Core\Billing\Catalogue\Http\Controllers\CatalogueBundleController;
use App\Core\Billing\Catalogue\Http\Controllers\CatalogueCouponController;
use App\Core\Billing\Catalogue\Http\Controllers\CatalogueModuleController;
use App\Core\Billing\Catalogue\Http\Controllers\CataloguePriceController;
use App\Core\Billing\Catalogue\Http\Controllers\PublicCatalogueController;
use App\Core\Billing\Catalogue\Models\AnnualDiscount;
use App\Core\Billing\Catalogue\Models\CatalogueBundle;
use App\Core\Billing\Catalogue\Models\CatalogueCoupon;
use App\Core\Billing\Catalogue\Models\CatalogueModule;
use App\Core\Billing\Catalogue\Models\CataloguePrice;
use App\Core\Platform\Http\Controllers\PlatformAuthController;
use App\Core\Platform\Http\Middleware\EnsurePlatformAccess;
use App\Core\Platform\PlatformServiceProvider;
use Illuminate\Support\Facades\Route;

Route::model('catalogue_module', CatalogueModule::class);
Route::model('catalogue_bundle', CatalogueBundle::class);
Route::model('catalogue_price', CataloguePrice::class);
Route::model('catalogue_coupon', CatalogueCoupon::class);
Route::model('annual_discount', AnnualDiscount::class);

// ONB-03: the public catalogue and quote for the module picker, before
// sign-up. Global data only; rate-limited per IP.
Route::middleware('throttle:'.PlatformServiceProvider::PUBLIC_CATALOGUE_LIMITER)->group(function () {
    Route::get('catalogue', [PublicCatalogueController::class, 'index']);
    Route::post('catalogue/quote', [PublicCatalogueController::class, 'quote']);
});

Route::prefix('platform')->group(function () {
    $ip = 'throttle:'.PlatformServiceProvider::AUTH_IP_LIMITER;

    // Staff sign-in (password, then a TOTP code) and the one-time setup link.
    Route::post('auth/sign-in', [PlatformAuthController::class, 'signIn'])->middleware([$ip, 'throttle:'.PlatformServiceProvider::AUTH_LOGIN_LIMITER]);
    Route::post('auth/two-factor', [PlatformAuthController::class, 'challenge'])->middleware($ip);
    Route::get('auth/setup/{token}', [PlatformAuthController::class, 'showSetup'])->middleware($ip)->where('token', '[A-Za-z0-9]{48}');
    Route::post('auth/setup/{token}', [PlatformAuthController::class, 'setup'])->middleware($ip)->where('token', '[A-Za-z0-9]{48}');

    // A staff token only (`auth:platform`); never a tenant or device token.
    Route::middleware(['auth:platform', EnsurePlatformAccess::class.':enrol'])->group(function () use ($ip) {
        Route::get('me', [PlatformAuthController::class, 'me']);
        Route::post('auth/sign-out', [PlatformAuthController::class, 'signOut']);
        Route::post('me/two-factor/totp', [PlatformAuthController::class, 'startTotp']);
        Route::post('me/two-factor/totp/confirm', [PlatformAuthController::class, 'confirmTotp'])->middleware($ip);
    });

    // Full access: a token issued after two-factor. Roles are checked by
    // each Form Request (catalogue.view: every role; catalogue.manage: owner, billing).
    Route::middleware(['auth:platform', EnsurePlatformAccess::class])->prefix('catalogue')->group(function () {
        Route::get('modules', [CatalogueModuleController::class, 'index']);
        Route::post('modules', [CatalogueModuleController::class, 'store']);
        Route::patch('modules/{catalogue_module}', [CatalogueModuleController::class, 'update'])->whereUuid('catalogue_module');
        Route::post('modules/{catalogue_module}/archive', [CatalogueModuleController::class, 'archive'])->whereUuid('catalogue_module');
        Route::post('modules/{catalogue_module}/restore', [CatalogueModuleController::class, 'restore'])->whereUuid('catalogue_module');

        Route::get('bundles', [CatalogueBundleController::class, 'index']);
        Route::post('bundles', [CatalogueBundleController::class, 'store']);
        Route::patch('bundles/{catalogue_bundle}', [CatalogueBundleController::class, 'update'])->whereUuid('catalogue_bundle');
        Route::post('bundles/{catalogue_bundle}/archive', [CatalogueBundleController::class, 'archive'])->whereUuid('catalogue_bundle');
        Route::post('bundles/{catalogue_bundle}/restore', [CatalogueBundleController::class, 'restore'])->whereUuid('catalogue_bundle');

        Route::get('prices', [CataloguePriceController::class, 'index']);
        Route::post('prices', [CataloguePriceController::class, 'store']);
        Route::post('prices/{catalogue_price}/archive', [CataloguePriceController::class, 'archive'])->whereUuid('catalogue_price');

        Route::get('coupons', [CatalogueCouponController::class, 'index']);
        Route::post('coupons', [CatalogueCouponController::class, 'store']);
        Route::patch('coupons/{catalogue_coupon}', [CatalogueCouponController::class, 'update'])->whereUuid('catalogue_coupon');
        Route::post('coupons/{catalogue_coupon}/archive', [CatalogueCouponController::class, 'archive'])->whereUuid('catalogue_coupon');

        Route::get('annual-discounts', [AnnualDiscountController::class, 'index']);
        Route::post('annual-discounts', [AnnualDiscountController::class, 'store']);
        Route::post('annual-discounts/{annual_discount}/archive', [AnnualDiscountController::class, 'archive'])->whereUuid('annual_discount');
    });
});
