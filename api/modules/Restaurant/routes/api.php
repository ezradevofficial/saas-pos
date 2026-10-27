<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\Restaurant\Http\Controllers\DeviceController;
use Modules\Restaurant\Http\Controllers\FloorController;
use Modules\Restaurant\Http\Controllers\ModifierController;
use Modules\Restaurant\Http\Controllers\OrderController;
use Modules\Restaurant\Http\Controllers\ReportController;
use Modules\Restaurant\Http\Controllers\SettingsController;
use Modules\Restaurant\Http\Controllers\StationController;
use Modules\Restaurant\Http\Controllers\TicketController;
use Modules\Restaurant\Models\Area;
use Modules\Restaurant\Models\ModifierGroup;
use Modules\Restaurant\Models\Order;
use Modules\Restaurant\Models\OrderLine;
use Modules\Restaurant\Models\Station;
use Modules\Restaurant\Models\Table;
use Modules\Restaurant\Models\Ticket;

// Restaurant module routes (docs/modules/restaurant.md), prefix /api/v1,
// loaded by RestaurantServiceProvider. RBAC-08 (REST-01): every route is
// closed (403 `module_inactive`) unless the tenant has the Restaurant
// module active. A route names the permission it needs with
// `->defaults('permission', ...)`; its request checks it at the place the
// request touches (RBAC-04).

foreach (['rest_area', 'rest_table', 'rest_station', 'rest_modifier_group', 'rest_order', 'rest_order_line', 'rest_ticket'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('rest_area', Area::class);
Route::model('rest_table', Table::class);
Route::model('rest_station', Station::class);
Route::model('rest_modifier_group', ModifierGroup::class);
Route::model('rest_order', Order::class);
Route::model('rest_order_line', OrderLine::class);
Route::model('rest_ticket', Ticket::class);

// REST-17, REST-09: what a paired till sends (device token only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:restaurant'])->group(function () {
    Route::post('restaurant/orders/sync', [DeviceController::class, 'sync']);
    Route::post('restaurant/device/tickets/{rest_ticket}/status', [DeviceController::class, 'ticketStatus']);
});

// The back office and the kitchen display (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:restaurant'])->group(function () {
    // REST-02: the floor plan.
    Route::get('restaurant/areas', [FloorController::class, 'areas'])->defaults('permission', 'restaurant.floor.view');
    Route::post('restaurant/areas', [FloorController::class, 'storeArea'])->defaults('permission', 'restaurant.floor.manage');
    Route::put('restaurant/areas/{rest_area}', [FloorController::class, 'updateArea'])->defaults('permission', 'restaurant.floor.manage');
    Route::post('restaurant/areas/{rest_area}/archive', [FloorController::class, 'archiveArea'])->defaults('permission', 'restaurant.floor.manage');
    Route::get('restaurant/tables', [FloorController::class, 'tables'])->defaults('permission', 'restaurant.floor.view');
    Route::post('restaurant/tables', [FloorController::class, 'storeTable'])->defaults('permission', 'restaurant.floor.manage');
    Route::put('restaurant/tables/{rest_table}', [FloorController::class, 'updateTable'])->defaults('permission', 'restaurant.floor.manage');
    Route::post('restaurant/tables/{rest_table}/status', [FloorController::class, 'tableStatus'])->defaults('permission', 'restaurant.floor.manage');
    Route::post('restaurant/tables/{rest_table}/archive', [FloorController::class, 'archiveTable'])->defaults('permission', 'restaurant.floor.manage');

    // REST-08: stations and routing.
    Route::get('restaurant/stations', [StationController::class, 'index'])->defaults('permission', 'restaurant.station.view');
    Route::post('restaurant/stations', [StationController::class, 'store'])->defaults('permission', 'restaurant.station.manage');
    Route::put('restaurant/stations/{rest_station}', [StationController::class, 'update'])->defaults('permission', 'restaurant.station.manage');
    Route::post('restaurant/stations/{rest_station}/archive', [StationController::class, 'archive'])->defaults('permission', 'restaurant.station.manage');

    // REST-07: modifier groups.
    Route::get('restaurant/modifier-groups', [ModifierController::class, 'index'])->defaults('permission', 'restaurant.modifier.view');
    Route::post('restaurant/modifier-groups', [ModifierController::class, 'store'])->defaults('permission', 'restaurant.modifier.manage');
    Route::put('restaurant/modifier-groups/{rest_modifier_group}', [ModifierController::class, 'update'])->defaults('permission', 'restaurant.modifier.manage');
    Route::post('restaurant/modifier-groups/{rest_modifier_group}/archive', [ModifierController::class, 'archive'])->defaults('permission', 'restaurant.modifier.manage');

    // REST-13: a company's service charge and tip items.
    Route::get('restaurant/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('restaurant/companies/{company}/settings', [SettingsController::class, 'update'])->defaults('permission', 'restaurant.settings.edit');

    // REST-03 to REST-06, REST-11, REST-14, REST-15: orders.
    Route::get('restaurant/orders', [OrderController::class, 'index'])->defaults('permission', 'restaurant.order.view');
    Route::get('restaurant/orders/{rest_order}', [OrderController::class, 'show'])->defaults('permission', 'restaurant.order.view');
    Route::post('restaurant/orders/{rest_order}/transfer', [OrderController::class, 'transfer'])->defaults('permission', 'restaurant.order.transfer');
    Route::post('restaurant/orders/{rest_order}/merge', [OrderController::class, 'merge'])->defaults('permission', 'restaurant.order.merge');
    Route::post('restaurant/orders/{rest_order}/lines/{rest_order_line}/void', [OrderController::class, 'voidLine'])->defaults('permission', 'restaurant.order.edit');
    Route::post('restaurant/orders/{rest_order}/lines/{rest_order_line}/void/reject', [OrderController::class, 'rejectVoid'])->defaults('permission', 'pos.sale.void');
    Route::post('restaurant/orders/{rest_order}/cancel', [OrderController::class, 'cancel'])->defaults('permission', 'restaurant.order.edit');
    Route::post('restaurant/orders/{rest_order}/split', [OrderController::class, 'split'])->defaults('permission', 'restaurant.order.close');

    // REST-09, REST-12: the kitchen display.
    Route::get('restaurant/tickets', [TicketController::class, 'index'])->defaults('permission', 'restaurant.ticket.view');
    Route::post('restaurant/tickets/{rest_ticket}/status', [TicketController::class, 'status'])->defaults('permission', 'restaurant.ticket.update');

    // REST-18: reports.
    Route::get('restaurant/reports/summary', [ReportController::class, 'summary'])->defaults('permission', 'restaurant.report.view');
});
