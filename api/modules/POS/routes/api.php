<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\POS\Http\Controllers\Device\DeviceUploadController;
use Modules\POS\Http\Controllers\SaleController;
use Modules\POS\Http\Controllers\ShiftController;
use Modules\POS\Models\Sale;
use Modules\POS\Models\Shift;

// POS module routes (docs/modules/pos.md), prefix /api/v1, loaded by
// PosServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the POS module active.

foreach (['pos_sale', 'pos_shift'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('pos_sale', Sale::class);
Route::model('pos_shift', Shift::class);

// TEN-05, POS-09, NUM-02: what a paired till sends (device token only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:pos'])->group(function () {
    Route::post('pos/number-ranges', [DeviceUploadController::class, 'numberRanges']);
    Route::post('pos/shifts', [DeviceUploadController::class, 'shifts']);
    Route::post('pos/sales', [DeviceUploadController::class, 'sales']);
    Route::post('pos/cash-movements', [DeviceUploadController::class, 'cashMovements']);
    Route::post('pos/voids', [DeviceUploadController::class, 'voids']);
    Route::post('pos/refunds', [DeviceUploadController::class, 'refunds']);
});

// POS-12: the back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:pos'])->group(function () {
    Route::get('pos/sales', [SaleController::class, 'index']);
    Route::get('pos/sales/{pos_sale}', [SaleController::class, 'show']);
    Route::get('pos/shifts', [ShiftController::class, 'index']);
    Route::get('pos/shifts/{pos_shift}', [ShiftController::class, 'show']);
});
