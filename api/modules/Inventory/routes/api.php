<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\AdjustmentController;
use Modules\Inventory\Http\Controllers\CountController;
use Modules\Inventory\Http\Controllers\LabelController;
use Modules\Inventory\Http\Controllers\ReorderController;
use Modules\Inventory\Http\Controllers\SettingsController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\TransferController;
use Modules\Inventory\Models\Adjustment;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Models\Transfer;

// Inventory module routes (docs/modules/inventory.md), prefix /api/v1,
// loaded by InventoryServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the Inventory module active.
// People's tokens only; tills read stock through the sync API (INV-17).

foreach (['inventory_transfer', 'inventory_adjustment', 'inventory_count'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('inventory_transfer', Transfer::class);
Route::model('inventory_adjustment', Adjustment::class);
Route::model('inventory_count', StockCount::class);

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:inventory'])->group(function () {
    // INV-12, INV-13: a company's costing method and negative-stock rule.
    Route::get('inventory/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('inventory/companies/{company}/settings', [SettingsController::class, 'update']);

    // INV-04, INV-05, INV-19, INV-20: stock on hand, the ledger, the valuation.
    Route::get('inventory/stock', [StockController::class, 'index'])->middleware('public-api:inventory.stock');
    Route::get('inventory/movements', [StockController::class, 'movements']);
    Route::get('inventory/valuation', [StockController::class, 'valuation']);

    // INV-01, INV-02, INV-06, INV-07: an item's stock detail, tracking and variant link.
    Route::get('inventory/items/{item}', [StockController::class, 'item']);
    Route::get('inventory/items/{item}/movements', [StockController::class, 'itemMovements']);
    Route::put('inventory/items/{item}/settings', [StockController::class, 'settings']);
    Route::put('inventory/items/{item}/variant', [StockController::class, 'variant']);

    // INV-18: opening stock, one line at a time.
    Route::post('inventory/opening-stock', [StockController::class, 'opening']);

    // INV-11: reorder levels.
    Route::get('inventory/reorder-rules', [ReorderController::class, 'index']);
    Route::put('inventory/reorder-rules', [ReorderController::class, 'upsert']);

    // INV-08: transfers.
    Route::get('inventory/transfers', [TransferController::class, 'index']);
    Route::post('inventory/transfers', [TransferController::class, 'store']);
    Route::get('inventory/transfers/{inventory_transfer}', [TransferController::class, 'show']);
    Route::post('inventory/transfers/{inventory_transfer}/dispatch', [TransferController::class, 'dispatch']);
    Route::post('inventory/transfers/{inventory_transfer}/receive', [TransferController::class, 'receive']);
    Route::post('inventory/transfers/{inventory_transfer}/cancel', [TransferController::class, 'cancel']);

    // INV-10: adjustments.
    Route::get('inventory/adjustments', [AdjustmentController::class, 'index']);
    Route::post('inventory/adjustments', [AdjustmentController::class, 'store']);
    Route::get('inventory/adjustments/{inventory_adjustment}', [AdjustmentController::class, 'show']);
    Route::post('inventory/adjustments/{inventory_adjustment}/cancel', [AdjustmentController::class, 'cancel']);

    // INV-09: stock counts.
    Route::get('inventory/counts', [CountController::class, 'index']);
    Route::post('inventory/counts', [CountController::class, 'store']);
    Route::get('inventory/counts/{inventory_count}', [CountController::class, 'show']);
    Route::put('inventory/counts/{inventory_count}/lines', [CountController::class, 'lines']);
    Route::post('inventory/counts/{inventory_count}/submit', [CountController::class, 'submit']);
    Route::post('inventory/counts/{inventory_count}/cancel', [CountController::class, 'cancel']);

    // INV-21: barcode labels through document templates.
    Route::post('inventory/labels', LabelController::class);
});
