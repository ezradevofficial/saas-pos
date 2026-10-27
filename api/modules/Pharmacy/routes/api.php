<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\MasterData\Items\Item;
use Illuminate\Support\Facades\Route;
use Modules\Pharmacy\Http\Controllers\DispenseController;
use Modules\Pharmacy\Http\Controllers\ItemProfileController;
use Modules\Pharmacy\Http\Controllers\LotController;
use Modules\Pharmacy\Http\Controllers\PrescriptionController;
use Modules\Pharmacy\Http\Controllers\RecallController;
use Modules\Pharmacy\Http\Controllers\ReceiptController;
use Modules\Pharmacy\Http\Controllers\RegisterController;
use Modules\Pharmacy\Http\Controllers\ReportController;
use Modules\Pharmacy\Http\Controllers\SettingsController;
use Modules\Pharmacy\Http\Controllers\TransferController;
use Modules\Pharmacy\Http\Controllers\WriteOffController;
use Modules\Pharmacy\Models\Dispense;
use Modules\Pharmacy\Models\Lot;
use Modules\Pharmacy\Models\Prescription;
use Modules\Pharmacy\Models\Recall;
use Modules\Pharmacy\Models\Receipt;
use Modules\Pharmacy\Models\WriteOff;

// Pharmacy module routes (docs/modules/pharmacy.md), prefix /api/v1, loaded by
// PharmacyServiceProvider. RBAC-08: every route is closed (403 `module_inactive`)
// unless the tenant has the module active. Tills use no route of their own:
// they pull the pharmacy sync entities and upload sales as usual (PHA-25).

foreach (['pharmacy_item', 'pharmacy_lot', 'pharmacy_receipt', 'pharmacy_write_off', 'pharmacy_recall', 'pharmacy_prescription', 'pharmacy_dispense'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('pharmacy_item', Item::class);
Route::model('pharmacy_lot', Lot::class);
Route::model('pharmacy_receipt', Receipt::class);
Route::model('pharmacy_write_off', WriteOff::class);
Route::model('pharmacy_recall', Recall::class);
Route::model('pharmacy_prescription', Prescription::class);
Route::model('pharmacy_dispense', Dispense::class);

// PHA-15: a prescription image behind a temporary signed URL for one user (the controller checks again).
Route::get('pharmacy-files/{path}', [PrescriptionController::class, 'download'])->where('path', 'tenants/.+')->middleware(['throttle:media', 'signed'])->name('pharmacy.prescription.file');

// The back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:pharmacy'])->group(function () {
    // PHA-02
    Route::get('companies/{company}/pharmacy-settings', [SettingsController::class, 'show']);
    Route::put('companies/{company}/pharmacy-settings', [SettingsController::class, 'update']);

    // PHA-03, PHA-20, PHA-21
    Route::get('pharmacy/items', [ItemProfileController::class, 'index']);
    Route::get('pharmacy/items/{pharmacy_item}/profile', [ItemProfileController::class, 'show']);
    Route::put('pharmacy/items/{pharmacy_item}/profile', [ItemProfileController::class, 'update']);

    // PHA-04, PHA-05, PHA-07, PHA-11
    Route::get('pharmacy/lots', [LotController::class, 'index']);
    Route::get('pharmacy/fefo', [LotController::class, 'fefo']);
    Route::get('pharmacy/lots/{pharmacy_lot}', [LotController::class, 'show']);
    Route::post('pharmacy/lots/{pharmacy_lot}/quarantine', [LotController::class, 'quarantine']);
    Route::post('pharmacy/lots/{pharmacy_lot}/release', [LotController::class, 'release']);

    // PHA-06, PHA-13
    Route::post('pharmacy/receipts', [ReceiptController::class, 'store']);
    Route::get('pharmacy/receipts/{pharmacy_receipt}', [ReceiptController::class, 'show']);
    Route::post('pharmacy/transfers', [TransferController::class, 'store']);

    // PHA-12
    Route::get('pharmacy/write-offs', [WriteOffController::class, 'index']);
    Route::post('pharmacy/write-offs', [WriteOffController::class, 'store']);
    Route::get('pharmacy/write-offs/{pharmacy_write_off}', [WriteOffController::class, 'show']);
    Route::post('pharmacy/write-offs/{pharmacy_write_off}/cancel', [WriteOffController::class, 'cancel']);

    // PHA-22, PHA-23
    Route::get('pharmacy/recalls', [RecallController::class, 'index']);
    Route::post('pharmacy/recalls', [RecallController::class, 'store']);
    Route::get('pharmacy/recalls/{pharmacy_recall}', [RecallController::class, 'show']);
    Route::get('pharmacy/recalls/{pharmacy_recall}/customers', [RecallController::class, 'customers']);
    Route::post('pharmacy/recalls/{pharmacy_recall}/lift', [RecallController::class, 'lift']);

    // PHA-14, PHA-15, PHA-28
    Route::get('pharmacy/prescriptions', [PrescriptionController::class, 'index']);
    Route::post('pharmacy/prescriptions', [PrescriptionController::class, 'store']);
    Route::get('pharmacy/prescriptions/{pharmacy_prescription}', [PrescriptionController::class, 'show']);
    Route::post('pharmacy/prescriptions/{pharmacy_prescription}/cancel', [PrescriptionController::class, 'cancel']);
    Route::post('pharmacy/prescriptions/{pharmacy_prescription}/image', [PrescriptionController::class, 'image']);
    Route::get('pharmacy/prescriptions/{pharmacy_prescription}/image-link', [PrescriptionController::class, 'imageLink']);

    // PHA-18, PHA-19
    Route::get('pharmacy/register', [RegisterController::class, 'index']);
    Route::get('pharmacy/register/print', [RegisterController::class, 'print']);
    Route::post('pharmacy/register/adjustments', [RegisterController::class, 'adjust']);

    // PHA-16, PHA-17, PHA-24, PHA-26
    Route::get('pharmacy/dispenses', [DispenseController::class, 'index']);
    Route::post('pharmacy/dispenses/{pharmacy_dispense}/review', [DispenseController::class, 'review']);

    // PHA-24
    Route::get('pharmacy/reports/expiry', [ReportController::class, 'expiry']);
    Route::get('pharmacy/reports/slow-movers', [ReportController::class, 'slowMovers']);
    Route::get('pharmacy/reports/prescriptions', [DispenseController::class, 'log']);
});
