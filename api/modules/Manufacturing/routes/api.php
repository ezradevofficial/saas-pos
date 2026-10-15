<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Manufacturing\Http\Controllers\BomController;
use Modules\Manufacturing\Http\Controllers\OrderController;
use Modules\Manufacturing\Http\Controllers\ReportController;
use Modules\Manufacturing\Models\Bom;
use Modules\Manufacturing\Models\BomVersion;
use Modules\Manufacturing\Models\Order;

// Manufacturing module routes (docs/modules/manufacturing.md), prefix
// /api/v1, loaded by ManufacturingServiceProvider. RBAC-08 (MFG-01): every
// route is closed (403 `module_inactive`) unless the tenant has the
// Manufacturing module active. People's tokens only.

foreach (['mfg_bom', 'mfg_bom_version', 'mfg_order'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('mfg_bom', Bom::class);
Route::model('mfg_bom_version', BomVersion::class);
Route::model('mfg_order', Order::class);

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:manufacturing'])->group(function () {
    // MFG-07, MFG-17: which integrations are on now.
    Route::get('manufacturing/status', [ReportController::class, 'status']);

    // MFG-29: a company's labour and overhead rates and release approval.
    Route::get('manufacturing/companies/{company}/settings', [ReportController::class, 'settings']);
    Route::put('manufacturing/companies/{company}/settings', [ReportController::class, 'updateSettings']);

    // MFG-02 to MFG-05: BOMs and recipes, versions, explosions.
    Route::get('manufacturing/boms', [BomController::class, 'index']);
    Route::post('manufacturing/boms', [BomController::class, 'store']);
    Route::get('manufacturing/boms/{mfg_bom}', [BomController::class, 'show']);
    Route::put('manufacturing/boms/{mfg_bom}', [BomController::class, 'update']);
    Route::post('manufacturing/boms/{mfg_bom}/archive', [BomController::class, 'archive']);
    Route::post('manufacturing/boms/{mfg_bom}/versions', [BomController::class, 'newVersion']);
    Route::get('manufacturing/bom-versions/{mfg_bom_version}', [BomController::class, 'version']);
    Route::put('manufacturing/bom-versions/{mfg_bom_version}', [BomController::class, 'saveVersion']);
    Route::post('manufacturing/bom-versions/{mfg_bom_version}/publish', [BomController::class, 'publish']);
    Route::get('manufacturing/bom-versions/{mfg_bom_version}/explode', [BomController::class, 'explode']);

    // MFG-10 to MFG-16, MFG-24: production orders.
    Route::get('manufacturing/orders', [OrderController::class, 'index']);
    Route::post('manufacturing/orders', [OrderController::class, 'store']);
    Route::get('manufacturing/orders/{mfg_order}', [OrderController::class, 'show']);
    Route::put('manufacturing/orders/{mfg_order}', [OrderController::class, 'update']);
    Route::post('manufacturing/orders/{mfg_order}/release', [OrderController::class, 'release']);
    Route::post('manufacturing/orders/{mfg_order}/cancel', [OrderController::class, 'cancel']);
    Route::post('manufacturing/orders/{mfg_order}/close', [OrderController::class, 'close']);
    Route::post('manufacturing/orders/{mfg_order}/issues', [OrderController::class, 'issue']);
    Route::post('manufacturing/orders/{mfg_order}/returns', [OrderController::class, 'returnMaterials']);
    Route::post('manufacturing/orders/{mfg_order}/reports', [OrderController::class, 'report']);
    Route::get('manufacturing/orders/{mfg_order}/print', [OrderController::class, 'printDocument']);

    // MFG-06, MFG-07, MFG-18, MFG-19: reports.
    Route::get('manufacturing/consumption', [ReportController::class, 'consumption'])->defaults('list', 'manufacturing-consumption');
    Route::get('manufacturing/yield', [ReportController::class, 'yield'])->defaults('list', 'manufacturing-yield');
    Route::get('manufacturing/backflushes', [ReportController::class, 'backflushes'])->defaults('list', 'manufacturing-backflushes');
    Route::get('manufacturing/recipe-costing', [ReportController::class, 'recipeCosting'])->defaults('list', 'manufacturing-recipe-costing');
});
