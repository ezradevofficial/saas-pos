<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Ecommerce\Courier\CourierApiServiceProvider;
use Modules\Ecommerce\Http\Controllers\Courier\CourierDeliveryController;
use Modules\Ecommerce\Http\Controllers\Courier\CourierOrderController;

// MKT-04, API-01 (ADR 020, ADR 024): the courier's public API routes,
// prefix /api/v1, loaded by CourierApiServiceProvider. They take API keys
// and app tokens (`public-api:<resource>`) as well as people's tokens; the
// module must be active (RBAC-08). Rate-limited per caller, besides the
// public API's own limits per key and tenant (API-03).

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:ecommerce'])->group(function () {
    Route::middleware(['throttle:'.CourierApiServiceProvider::READS_LIMITER, 'public-api:ecommerce.order'])->group(function () {
        Route::get('ecommerce/courier/orders', [CourierOrderController::class, 'index']);
        Route::get('ecommerce/courier/orders/{ecom_order}', [CourierOrderController::class, 'show']);
    });

    Route::middleware(['throttle:'.CourierApiServiceProvider::WRITES_LIMITER, 'public-api:ecommerce.delivery'])->group(function () {
        Route::post('ecommerce/courier/deliveries/{ecom_delivery}/booking', [CourierDeliveryController::class, 'book']);
        Route::post('ecommerce/courier/deliveries/{ecom_delivery}/status', [CourierDeliveryController::class, 'status']);
    });
});
