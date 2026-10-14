<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\Loyalty\Http\Controllers\AccountController;
use Modules\Loyalty\Http\Controllers\CouponController;
use Modules\Loyalty\Http\Controllers\CustomerController;
use Modules\Loyalty\Http\Controllers\Device\GiftCardLookupController;
use Modules\Loyalty\Http\Controllers\GiftCardController;
use Modules\Loyalty\Http\Controllers\ProgrammeController;
use Modules\Loyalty\Http\Controllers\PromotionController;
use Modules\Loyalty\Http\Controllers\SaleController;
use Modules\Loyalty\Http\Controllers\StatementController;
use Modules\Loyalty\LoyaltyServiceProvider;
use Modules\Loyalty\Models\Account;
use Modules\Loyalty\Models\Coupon;
use Modules\Loyalty\Models\GiftCard;
use Modules\Loyalty\Models\Promotion;
use Modules\Loyalty\Models\Statement;

// Loyalty module routes (docs/modules/loyalty.md), prefix /api/v1, loaded
// by LoyaltyServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the module active.

foreach (['loyalty_promotion', 'loyalty_coupon', 'loyalty_account', 'loyalty_statement', 'loyalty_gift_card'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('loyalty_promotion', Promotion::class);
Route::model('loyalty_coupon', Coupon::class);
Route::model('loyalty_account', Account::class);
Route::model('loyalty_statement', Statement::class);
Route::model('loyalty_gift_card', GiftCard::class);

// LOY-23: a till's live gift card balance check (device token only; tills belong to POS, RBAC-08).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:pos', 'module:loyalty'])->group(function () {
    // Guessing codes is throttled (loyalty-lookup).
    Route::post('loyalty/device/gift-cards/lookup', GiftCardLookupController::class)->middleware('throttle:'.LoyaltyServiceProvider::LOOKUP_LIMITER);
});

// The back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:loyalty'])->group(function () {
    // LOY-01
    Route::get('companies/{company}/loyalty-programme', [ProgrammeController::class, 'show']);
    Route::put('companies/{company}/loyalty-programme', [ProgrammeController::class, 'update']);

    // LOY-08, LOY-09, LOY-13
    Route::get('loyalty/promotions', [PromotionController::class, 'index']);
    Route::post('loyalty/promotions', [PromotionController::class, 'store']);
    Route::post('loyalty/promotions/preview', [PromotionController::class, 'preview']);
    Route::get('loyalty/promotions/{loyalty_promotion}', [PromotionController::class, 'show']);
    Route::patch('loyalty/promotions/{loyalty_promotion}', [PromotionController::class, 'update']);
    Route::post('loyalty/promotions/{loyalty_promotion}/activate', [PromotionController::class, 'activate']);
    Route::post('loyalty/promotions/{loyalty_promotion}/archive', [PromotionController::class, 'archive']);
    Route::get('loyalty/promotions/{loyalty_promotion}/coupons', [CouponController::class, 'index']);
    Route::post('loyalty/promotions/{loyalty_promotion}/coupons', [CouponController::class, 'store']);
    Route::post('loyalty/coupons/{loyalty_coupon}/archive', [CouponController::class, 'archive']);

    // LOY-12, H2: flagged sales to review.
    Route::get('loyalty/sales', [SaleController::class, 'index']);

    // LOY-02, LOY-07, LOY-14: the customer page's Loyalty tab.
    Route::get('loyalty/customers/{party}', [CustomerController::class, 'show']);
    Route::post('loyalty/customers/{party}/points/adjust', [CustomerController::class, 'adjustPoints']);

    // LOY-14..LOY-18
    Route::get('loyalty/accounts', [AccountController::class, 'index']);
    Route::post('loyalty/accounts', [AccountController::class, 'store']);
    Route::get('loyalty/ageing', [AccountController::class, 'index']);
    Route::get('loyalty/accounts/{loyalty_account}', [AccountController::class, 'show']);
    Route::post('loyalty/accounts/{loyalty_account}/payments', [AccountController::class, 'payment']);
    Route::post('loyalty/accounts/{loyalty_account}/charges', [AccountController::class, 'charge']);
    Route::post('loyalty/accounts/{loyalty_account}/adjustments', [AccountController::class, 'adjust']);
    Route::post('loyalty/accounts/{loyalty_account}/close', [AccountController::class, 'close']);
    Route::post('loyalty/accounts/{loyalty_account}/statements', [StatementController::class, 'store']);
    Route::get('loyalty/statements/{loyalty_statement}', [StatementController::class, 'show']);
    Route::get('loyalty/statements/{loyalty_statement}/pdf', [StatementController::class, 'pdf']);

    // LOY-20..LOY-23
    Route::get('loyalty/gift-cards', [GiftCardController::class, 'index']);
    Route::post('loyalty/gift-cards', [GiftCardController::class, 'store']);
    Route::post('loyalty/gift-cards/lookup', [GiftCardController::class, 'lookup'])->middleware('throttle:'.LoyaltyServiceProvider::LOOKUP_LIMITER);
    Route::get('loyalty/gift-cards/{loyalty_gift_card}', [GiftCardController::class, 'show']);
    Route::post('loyalty/gift-cards/{loyalty_gift_card}/sell', [GiftCardController::class, 'sell']);
    Route::post('loyalty/gift-cards/{loyalty_gift_card}/block', [GiftCardController::class, 'block']);
    Route::post('loyalty/gift-cards/{loyalty_gift_card}/unblock', [GiftCardController::class, 'unblock']);
});
