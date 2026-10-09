<?php

use App\Core\DocumentTemplates\Models\DocumentShare;
use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\POS\Http\Controllers\Device\DeviceUploadController;
use Modules\POS\Http\Controllers\Device\SaleFiscalController;
use Modules\POS\Http\Controllers\HeldController;
use Modules\POS\Http\Controllers\InsightsController;
use Modules\POS\Http\Controllers\SaleController;
use Modules\POS\Http\Controllers\SaleDocumentController;
use Modules\POS\Http\Controllers\ShiftController;
use Modules\POS\Models\CashMovement;
use Modules\POS\Models\Refund;
use Modules\POS\Models\Sale;
use Modules\POS\Models\SaleVoid;
use Modules\POS\Models\Shift;

// POS module routes (docs/modules/pos.md), prefix /api/v1, loaded by
// PosServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the POS module active.

foreach (['pos_sale', 'pos_shift', 'pos_void', 'pos_refund', 'pos_cash_movement', 'document_share'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('pos_sale', Sale::class);
Route::model('pos_shift', Shift::class);
Route::model('pos_void', SaleVoid::class);
Route::model('pos_refund', Refund::class);
Route::model('pos_cash_movement', CashMovement::class);
Route::model('document_share', DocumentShare::class);

// TEN-05, POS-09, NUM-02: what a paired till sends (device token only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:pos'])->group(function () {
    Route::post('pos/number-ranges', [DeviceUploadController::class, 'numberRanges']);
    Route::post('pos/shifts', [DeviceUploadController::class, 'shifts']);
    Route::post('pos/sales', [DeviceUploadController::class, 'sales']);
    Route::post('pos/cash-movements', [DeviceUploadController::class, 'cashMovements']);
    Route::post('pos/voids', [DeviceUploadController::class, 'voids']);
    Route::post('pos/refunds', [DeviceUploadController::class, 'refunds']);

    // POS-10: the fiscal state of a sale of this till's location, for its receipt.
    Route::get('pos/sales/{pos_sale}/fiscal', SaleFiscalController::class);
});

// POS-12: the back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:pos'])->group(function () {
    Route::get('pos/sales', [SaleController::class, 'index']);
    Route::get('pos/sales/{pos_sale}', [SaleController::class, 'show']);
    // POS-10: the tax authority's answer for a sale, its refunds and void (the till's route is pos/sales/{id}/fiscal).
    Route::get('pos/sales/{pos_sale}/fiscal-status', [SaleController::class, 'fiscal']);
    Route::get('pos/shifts', [ShiftController::class, 'index']);
    Route::get('pos/shifts/{pos_shift}', [ShiftController::class, 'show']);

    // TEN-07: consolidated sales across companies, branches and locations.
    Route::get('pos/insights', InsightsController::class);

    // M3: a flagged sale acknowledged.
    Route::post('pos/sales/{pos_sale}/review', [SaleController::class, 'review']);

    // TPL-04: the sale's receipt printed, downloaded, emailed or shared by link.
    Route::get('pos/sales/{pos_sale}/receipt', [SaleDocumentController::class, 'receipt']);
    Route::post('pos/sales/{pos_sale}/email', [SaleDocumentController::class, 'email']);
    Route::post('pos/sales/{pos_sale}/share', [SaleDocumentController::class, 'share']);
    Route::get('pos/sales/{pos_sale}/shares', [SaleDocumentController::class, 'shares']);
    Route::post('pos/sales/{pos_sale}/shares/{document_share}/revoke', [SaleDocumentController::class, 'revoke']);

    // H2: money out from the till waiting for review, approved (applied) or rejected.
    Route::get('pos/held', [HeldController::class, 'index']);
    Route::post('pos/voids/{pos_void}/approve', [HeldController::class, 'approveVoid']);
    Route::post('pos/voids/{pos_void}/reject', [HeldController::class, 'rejectVoid']);
    Route::post('pos/refunds/{pos_refund}/approve', [HeldController::class, 'approveRefund']);
    Route::post('pos/refunds/{pos_refund}/reject', [HeldController::class, 'rejectRefund']);
    Route::post('pos/cash-movements/{pos_cash_movement}/approve', [HeldController::class, 'approveCashMovement']);
    Route::post('pos/cash-movements/{pos_cash_movement}/reject', [HeldController::class, 'rejectCashMovement']);
});
