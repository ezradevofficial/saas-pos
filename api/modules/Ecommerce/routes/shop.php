<?php

use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\EcommerceServiceProvider;
use Modules\Ecommerce\Http\Controllers\Shop\ShopAuthController;
use Modules\Ecommerce\Http\Controllers\Shop\ShopController;
use Modules\Ecommerce\Http\Controllers\Shop\ShopImageController;
use Modules\Ecommerce\Http\Controllers\Shop\WhatsAppWebhookController;
use Modules\Ecommerce\Http\Middleware\ResolveShop;
use Modules\Ecommerce\Http\Middleware\ShopSecurityHeaders;

// ECOM-05 to ECOM-08, ECOM-24: the public storefront API and the WhatsApp
// webhook, prefix /api/v1. No tenant token: the store is found by host and
// handle (ResolveShop, a security-definer lookup that returns only the
// tenant id), the webhook by its token. Parameters are plain strings
// (model binding would run before the tenant is entered). Rate-limited.

Route::middleware([ShopSecurityHeaders::class, 'throttle:'.EcommerceServiceProvider::SHOP_LIMITER])->group(function () {
    // ECOM-02: an item image behind a temporary signed URL (the signature is the credential).
    Route::get('shop-media/{image}', ShopImageController::class)->where('image', '[0-9a-fA-F-]{36}')->middleware('signed')->name('ecommerce.shop.image');

    Route::post('shop/whatsapp/{token}', WhatsAppWebhookController::class)->where('token', '[A-Za-z0-9]{1,64}')
        ->withoutMiddleware('throttle:'.EcommerceServiceProvider::SHOP_LIMITER)->middleware('throttle:'.EcommerceServiceProvider::WHATSAPP_LIMITER);

    Route::prefix('shop/{store}')->where(['store' => '[a-z0-9-]{1,60}'])->middleware(ResolveShop::class)->group(function () {
        Route::get('/', [ShopController::class, 'show']);
        Route::get('products', [ShopController::class, 'products']);
        Route::get('products/{slug}', [ShopController::class, 'product'])->where('slug', '[a-z0-9-]{1,120}');
        Route::post('quote', [ShopController::class, 'quote']);
        Route::post('orders', [ShopController::class, 'place'])->middleware('throttle:'.EcommerceServiceProvider::CHECKOUT_LIMITER);
        Route::get('orders/{number}', [ShopController::class, 'track'])->where('number', '[A-Za-z0-9-]{1,80}');
        Route::post('orders/{number}/pay', [ShopController::class, 'pay'])->where('number', '[A-Za-z0-9-]{1,80}')->middleware('throttle:'.EcommerceServiceProvider::CHECKOUT_LIMITER);
        Route::post('orders/{number}/check', [ShopController::class, 'check'])->where('number', '[A-Za-z0-9-]{1,80}');
        // ECOM-41: the proof of delivery photo, with the tracking token.
        Route::get('orders/{number}/proof-photo', [ShopController::class, 'proofPhoto'])->where('number', '[A-Za-z0-9-]{1,80}');
        Route::post('auth/code', [ShopAuthController::class, 'code'])->middleware('throttle:'.EcommerceServiceProvider::CODES_LIMITER);
        Route::post('auth/verify', [ShopAuthController::class, 'verify'])->middleware('throttle:'.EcommerceServiceProvider::VERIFY_LIMITER);
        Route::post('auth/sign-out', [ShopAuthController::class, 'signOut']);
        Route::get('account', [ShopAuthController::class, 'account']);
    });
});
