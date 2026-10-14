<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Stores\Http\Controllers\IssueController;
use Modules\Stores\Http\Controllers\LedgerController;
use Modules\Stores\Http\Controllers\RequisitionController;
use Modules\Stores\Http\Controllers\ReturnController;
use Modules\Stores\Models\Issue;
use Modules\Stores\Models\Requisition;
use Modules\Stores\Models\StoreReturn;

// Stores module routes (docs/modules/stores.md), prefix /api/v1, loaded by
// StoresServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the Stores module active.
// People's tokens only.

foreach (['stores_requisition', 'stores_issue', 'stores_return'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('stores_requisition', Requisition::class);
Route::model('stores_issue', Issue::class);
Route::model('stores_return', StoreReturn::class);

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:stores'])->group(function () {
    // STR-07, STR-08: which ledger holds the stores' quantities now.
    Route::get('stores/status', [LedgerController::class, 'status']);

    // STR-02 to STR-04: requisitions, their cancellation, closing and issues.
    Route::get('stores/requisitions', [RequisitionController::class, 'index']);
    Route::post('stores/requisitions', [RequisitionController::class, 'store']);
    Route::get('stores/requisitions/{stores_requisition}', [RequisitionController::class, 'show']);
    Route::post('stores/requisitions/{stores_requisition}/cancel', [RequisitionController::class, 'cancel']);
    Route::post('stores/requisitions/{stores_requisition}/close', [RequisitionController::class, 'close']);
    Route::post('stores/requisitions/{stores_requisition}/issues', [RequisitionController::class, 'issue']);

    // STR-04 to STR-06, STR-14: issues, acknowledgement, returns and printing.
    Route::get('stores/issues', [IssueController::class, 'index']);
    Route::get('stores/issues/{stores_issue}', [IssueController::class, 'show']);
    Route::post('stores/issues/{stores_issue}/acknowledge', [IssueController::class, 'acknowledge']);
    Route::post('stores/issues/{stores_issue}/returns', [IssueController::class, 'returnGoods']);
    Route::get('stores/issues/{stores_issue}/print', [IssueController::class, 'printDocument']);

    Route::get('stores/returns', [ReturnController::class, 'index']);
    Route::get('stores/returns/{stores_return}', [ReturnController::class, 'show']);

    // STR-07, STR-10, STR-11: the store ledger, stock on hand and entries (own ledger).
    Route::get('stores/ledger', [LedgerController::class, 'ledger']);
    Route::get('stores/stock', [LedgerController::class, 'stock']);
    Route::post('stores/stock-entries', [LedgerController::class, 'entries']);

    // STR-12: consumption reports.
    Route::get('stores/consumption', [LedgerController::class, 'consumption']);
});
