<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\FixedAssets\Http\Controllers\AssetController;
use Modules\FixedAssets\Http\Controllers\DocumentController;
use Modules\FixedAssets\Http\Controllers\LabelController;
use Modules\FixedAssets\Http\Controllers\MaintenanceController;
use Modules\FixedAssets\Http\Controllers\MovementController;
use Modules\FixedAssets\Http\Controllers\ReportController;
use Modules\FixedAssets\Http\Controllers\RunController;
use Modules\FixedAssets\Http\Controllers\SetupController;
use Modules\FixedAssets\Models\Asset;
use Modules\FixedAssets\Models\AssetDocument;
use Modules\FixedAssets\Models\Category;
use Modules\FixedAssets\Models\DepreciationRun;
use Modules\FixedAssets\Models\Disposal;
use Modules\FixedAssets\Models\ItemCategory;
use Modules\FixedAssets\Models\MaintenanceSchedule;
use Modules\FixedAssets\Models\Transfer;
use Modules\FixedAssets\Models\WorkOrder;

// Fixed assets module routes (docs/modules/fixed-assets.md, API), prefix
// /api/v1, loaded by FixedAssetsServiceProvider. RBAC-08: every route is
// closed (403 `module_inactive`) unless the tenant has the module active.
// People's tokens only.

$models = [
    'fa_asset' => Asset::class,
    'fa_category' => Category::class,
    'fa_item_category' => ItemCategory::class,
    'fa_document' => AssetDocument::class,
    'fa_run' => DepreciationRun::class,
    'fa_transfer' => Transfer::class,
    'fa_disposal' => Disposal::class,
    'fa_schedule' => MaintenanceSchedule::class,
    'fa_work_order' => WorkOrder::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:fixed_assets'])->prefix('fixed-assets')->group(function () {
    // FA-01, FA-20: a company's settings and the tax book.
    Route::get('companies/{company}/settings', [SetupController::class, 'settings']);
    Route::put('companies/{company}/settings', [SetupController::class, 'updateSettings']);
    Route::get('companies/{company}/tax-book', [SetupController::class, 'taxBook']);

    // FA-02, FA-21: categories and items flagged as assets.
    Route::get('categories', [SetupController::class, 'categories']);
    Route::post('categories', [SetupController::class, 'storeCategory']);
    Route::patch('categories/{fa_category}', [SetupController::class, 'updateCategory']);
    Route::post('categories/{fa_category}/archive', [SetupController::class, 'archiveCategory']);
    Route::post('categories/{fa_category}/restore', [SetupController::class, 'restoreCategory']);
    Route::get('item-categories', [SetupController::class, 'itemCategories']);
    Route::post('item-categories', [SetupController::class, 'storeItemCategory']);
    Route::post('item-categories/{fa_item_category}/archive', [SetupController::class, 'archiveItemCategory']);

    // FA-04 to FA-06, FA-11, FA-18, FA-26: the register.
    Route::get('custodians', [AssetController::class, 'custodians']);
    Route::get('assets', [AssetController::class, 'index']);
    Route::post('assets', [AssetController::class, 'store']);
    Route::get('assets/{fa_asset}', [AssetController::class, 'show']);
    Route::patch('assets/{fa_asset}', [AssetController::class, 'update']);
    Route::post('assets/{fa_asset}/activate', [AssetController::class, 'activate']);
    Route::post('assets/{fa_asset}/cancel', [AssetController::class, 'cancel']);
    Route::post('assets/{fa_asset}/estimates', [AssetController::class, 'estimates']);
    Route::get('assets/{fa_asset}/schedule', [AssetController::class, 'schedule']);
    Route::get('assets/{fa_asset}/history', [AssetController::class, 'history']);
    Route::get('assets/{fa_asset}/readings', [AssetController::class, 'readings']);
    Route::post('assets/{fa_asset}/readings', [AssetController::class, 'read']);
    Route::get('assets/{fa_asset}/documents', [DocumentController::class, 'index']);
    Route::post('assets/{fa_asset}/documents', [DocumentController::class, 'store']);
    Route::get('documents/{fa_document}/download', [DocumentController::class, 'download']);
    Route::post('documents/{fa_document}/archive', [DocumentController::class, 'archive']);

    // FA-14 to FA-17: transfers, disposals, revaluations and impairments.
    Route::post('assets/{fa_asset}/transfers', [MovementController::class, 'storeTransfer']);
    Route::get('transfers', [MovementController::class, 'transfers']);
    Route::get('transfers/{fa_transfer}', [MovementController::class, 'transfer']);
    Route::post('transfers/{fa_transfer}/cancel', [MovementController::class, 'cancelTransfer']);
    Route::post('assets/{fa_asset}/disposals', [MovementController::class, 'storeDisposal']);
    Route::get('disposals', [MovementController::class, 'disposals']);
    Route::get('disposals/{fa_disposal}', [MovementController::class, 'disposal']);
    Route::post('disposals/{fa_disposal}/cancel', [MovementController::class, 'cancelDisposal']);
    Route::post('disposals/{fa_disposal}/post', [MovementController::class, 'postDisposal']);
    Route::post('assets/{fa_asset}/revaluations', [MovementController::class, 'storeRevaluation']);
    Route::get('revaluations', [MovementController::class, 'revaluations']);

    // FA-12, FA-13: depreciation runs.
    Route::get('runs', [RunController::class, 'index']);
    Route::get('runs/preview', [RunController::class, 'preview']);
    Route::post('runs', [RunController::class, 'store']);
    Route::get('runs/{fa_run}', [RunController::class, 'show']);
    Route::post('runs/{fa_run}/reverse', [RunController::class, 'reverse']);

    // FA-19: maintenance.
    Route::get('schedules', [MaintenanceController::class, 'schedules']);
    Route::post('assets/{fa_asset}/schedules', [MaintenanceController::class, 'storeSchedule']);
    Route::patch('schedules/{fa_schedule}', [MaintenanceController::class, 'updateSchedule']);
    Route::post('schedules/{fa_schedule}/archive', [MaintenanceController::class, 'archiveSchedule']);
    Route::get('work-orders', [MaintenanceController::class, 'orders']);
    Route::post('assets/{fa_asset}/work-orders', [MaintenanceController::class, 'storeOrder']);
    Route::get('work-orders/{fa_work_order}', [MaintenanceController::class, 'order']);
    Route::patch('work-orders/{fa_work_order}', [MaintenanceController::class, 'updateOrder']);

    // FA-25, FA-26: labels and reports.
    Route::post('labels', LabelController::class);
    Route::get('reports/depreciation', [ReportController::class, 'depreciation']);
    Route::get('reports/movements', [ReportController::class, 'movements']);
    Route::get('reports/net-book-value', [ReportController::class, 'netBookValue']);
});
