<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\VanSales\Http\Controllers\CollectionController;
use Modules\VanSales\Http\Controllers\CreditController;
use Modules\VanSales\Http\Controllers\DeviceController;
use Modules\VanSales\Http\Controllers\LoadController;
use Modules\VanSales\Http\Controllers\ReconciliationController;
use Modules\VanSales\Http\Controllers\ReportController;
use Modules\VanSales\Http\Controllers\RouteController;
use Modules\VanSales\Http\Controllers\RunController;
use Modules\VanSales\Http\Controllers\SettingsController;
use Modules\VanSales\Http\Controllers\VanController;
use Modules\VanSales\Models\Collection;
use Modules\VanSales\Models\Count;
use Modules\VanSales\Models\Load;
use Modules\VanSales\Models\Route as VanRoute;
use Modules\VanSales\Models\Run;
use Modules\VanSales\Models\Van;
use Modules\VanSales\Models\VanSale;

// Van sales module routes (docs/modules/van-sales.md), prefix /api/v1,
// loaded by VanSalesServiceProvider. RBAC-08 (VAN-01): every route is
// closed (403 `module_inactive`) unless the tenant has the Van sales module
// active. A route names the permission it needs with
// `->defaults('permission', ...)`; its request checks it at the place the
// request touches (RBAC-04).

foreach (['vs_van', 'vs_route', 'vs_load', 'vs_run', 'vs_collection', 'vs_count', 'vs_sale'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('vs_van', Van::class);
Route::model('vs_route', VanRoute::class);
Route::model('vs_load', Load::class);
Route::model('vs_run', Run::class);
Route::model('vs_collection', Collection::class);
Route::model('vs_count', Count::class);
Route::model('vs_sale', VanSale::class);

// VAN-27, VAN-08: what a paired van device sends (device token only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:van_sales'])->group(function () {
    Route::post('van-sales/device/sync', [DeviceController::class, 'sync']);
    Route::post('van-sales/device/loads/{vs_load}/confirm', [DeviceController::class, 'confirmLoad']);
});

// The back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:van_sales'])->group(function () {
    // VAN-02: vans.
    Route::get('van-sales/vans', [VanController::class, 'index'])->defaults('permission', 'van_sales.van.view');
    Route::post('van-sales/vans', [VanController::class, 'store'])->defaults('permission', 'van_sales.van.manage');
    Route::put('van-sales/vans/{vs_van}', [VanController::class, 'update'])->defaults('permission', 'van_sales.van.manage');
    Route::post('van-sales/vans/{vs_van}/archive', [VanController::class, 'archive'])->defaults('permission', 'van_sales.van.manage');

    // VAN-03, VAN-04, VAN-05: routes, stops and the plan.
    Route::get('van-sales/routes', [RouteController::class, 'index'])->defaults('permission', 'van_sales.route.view');
    Route::post('van-sales/routes', [RouteController::class, 'store'])->defaults('permission', 'van_sales.route.manage');
    Route::get('van-sales/routes/{vs_route}', [RouteController::class, 'show'])->defaults('permission', 'van_sales.route.view');
    Route::put('van-sales/routes/{vs_route}', [RouteController::class, 'update'])->defaults('permission', 'van_sales.route.manage');
    Route::post('van-sales/routes/{vs_route}/archive', [RouteController::class, 'archive'])->defaults('permission', 'van_sales.route.manage');
    Route::put('van-sales/routes/{vs_route}/stops', [RouteController::class, 'stops'])->defaults('permission', 'van_sales.route.manage');
    Route::get('van-sales/plan', [RouteController::class, 'plan'])->defaults('permission', 'van_sales.route.view');

    // VAN-06, VAN-24: runs and positions.
    Route::get('van-sales/runs', [RunController::class, 'index'])->defaults('permission', 'van_sales.run.view');
    Route::get('van-sales/runs/{vs_run}', [RunController::class, 'show'])->defaults('permission', 'van_sales.run.view');
    Route::get('van-sales/runs/{vs_run}/positions', [RunController::class, 'positions'])->defaults('permission', 'van_sales.visit.track');

    // VAN-07: load sheets.
    Route::get('van-sales/loads', [LoadController::class, 'index'])->defaults('permission', 'van_sales.load.view');
    Route::post('van-sales/loads', [LoadController::class, 'store'])->defaults('permission', 'van_sales.load.create');
    Route::get('van-sales/loads/{vs_load}', [LoadController::class, 'show'])->defaults('permission', 'van_sales.load.view');
    Route::post('van-sales/loads/{vs_load}/release', [LoadController::class, 'release'])->defaults('permission', 'van_sales.load.release');
    Route::post('van-sales/loads/{vs_load}/cancel', [LoadController::class, 'cancel'])->defaults('permission', 'van_sales.load.create');

    // VAN-17: collections.
    Route::get('van-sales/collections', [CollectionController::class, 'index'])->defaults('permission', 'van_sales.collection.view');
    Route::post('van-sales/collections/{vs_collection}/void', [CollectionController::class, 'void'])->defaults('permission', 'van_sales.collection.void');

    // VAN-11 to VAN-13: credit.
    Route::get('van-sales/accounts', [CreditController::class, 'accounts'])->defaults('permission', 'van_sales.credit.view');
    Route::get('van-sales/accounts/{party}', [CreditController::class, 'account'])->defaults('permission', 'van_sales.credit.view');
    Route::get('van-sales/credit-reviews', [CreditController::class, 'reviews'])->defaults('permission', 'van_sales.credit.view');
    Route::post('van-sales/credit-reviews/{vs_sale}/review', [CreditController::class, 'review'])->defaults('permission', 'van_sales.credit.review');

    // VAN-18 to VAN-23: counts and their reconciliation.
    Route::get('van-sales/reconciliations', [ReconciliationController::class, 'index'])->defaults('permission', 'van_sales.reconciliation.view');
    Route::get('van-sales/reconciliations/{vs_count}', [ReconciliationController::class, 'show'])->defaults('permission', 'van_sales.reconciliation.view');
    Route::post('van-sales/reconciliations/{vs_count}/unload/receive', [ReconciliationController::class, 'unloadReceive'])->defaults('permission', 'van_sales.unload.receive');

    // VAN-24, VAN-11: a company's settings.
    Route::get('van-sales/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('van-sales/companies/{company}/settings', [SettingsController::class, 'update'])->defaults('permission', 'van_sales.settings.edit');

    // VAN-25: reports.
    Route::get('van-sales/reports/summary', [ReportController::class, 'summary'])->defaults('permission', 'van_sales.report.view');
    Route::get('van-sales/reports/van-stock', [ReportController::class, 'vanStock'])->defaults('permission', 'van_sales.report.view');
});
