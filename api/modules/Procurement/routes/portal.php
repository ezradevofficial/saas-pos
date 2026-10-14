<?php

use Illuminate\Support\Facades\Route;
use Modules\Procurement\Http\Controllers\Portal\PortalController;
use Modules\Procurement\Http\Middleware\PortalSessionMiddleware;
use Modules\Procurement\ProcurementServiceProvider;

// The supplier portal (docs/modules/procurement.md, PRC-07 to PRC-09,
// PRC-23), prefix /api/v1. No tenant user token is ever accepted here:
// sign-in exchanges a bidder's emailed link (or registers on an open
// tender's public notice) and every other route takes only the portal
// session's bearer token (PortalSessionMiddleware), which enters the
// token's tenant and binds the request to its tender and bidder. Every
// route is rate-limited (PRC-23).

Route::middleware(['throttle:'.ProcurementServiceProvider::PORTAL_AUTH_LIMITER])->group(function () {
    Route::post('supplier-portal/sessions', [PortalController::class, 'start']);
    Route::get('supplier-portal/notices/{notice}', [PortalController::class, 'notice'])->where('notice', '[A-Za-z0-9]{48}');
    Route::post('supplier-portal/notices/{notice}/register', [PortalController::class, 'register'])->where('notice', '[A-Za-z0-9]{48}');
});

Route::middleware(['throttle:'.ProcurementServiceProvider::PORTAL_LIMITER, PortalSessionMiddleware::class])->group(function () {
    Route::delete('supplier-portal/sessions/current', [PortalController::class, 'end']);
    Route::get('supplier-portal/tender', [PortalController::class, 'tender']);
    Route::put('supplier-portal/bid', [PortalController::class, 'submit']);
    Route::post('supplier-portal/bid/files', [PortalController::class, 'upload']);
    Route::post('supplier-portal/bid/withdraw', [PortalController::class, 'withdraw']);
});
