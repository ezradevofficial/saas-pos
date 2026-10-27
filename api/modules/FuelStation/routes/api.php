<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\FuelStation\Http\Controllers\DeviceController;
use Modules\FuelStation\Http\Controllers\FleetController;
use Modules\FuelStation\Http\Controllers\MeterResetController;
use Modules\FuelStation\Http\Controllers\PriceController;
use Modules\FuelStation\Http\Controllers\ReportController;
use Modules\FuelStation\Http\Controllers\SettingsController;
use Modules\FuelStation\Http\Controllers\SetupController;
use Modules\FuelStation\Http\Controllers\ShiftController;
use Modules\FuelStation\Http\Controllers\TankController;
use Modules\FuelStation\Models\Delivery;
use Modules\FuelStation\Models\MeterReset;
use Modules\FuelStation\Models\Nozzle;
use Modules\FuelStation\Models\Price;
use Modules\FuelStation\Models\Product;
use Modules\FuelStation\Models\Pump;
use Modules\FuelStation\Models\Reconciliation;
use Modules\FuelStation\Models\Settlement;
use Modules\FuelStation\Models\Shift;
use Modules\FuelStation\Models\Station;
use Modules\FuelStation\Models\Tank;
use Modules\FuelStation\Models\Vehicle;
use Modules\FuelStation\Models\Voucher;

// Fuel station module routes (docs/modules/fuel-station.md), prefix /api/v1, loaded by
// FuelStationServiceProvider. RBAC-08: every route is closed (403 `module_inactive`)
// unless the tenant has the module active. A paired till uses two routes of its own
// (shifts and meter resets; fuel sales go through pos/sales); the rest is people's tokens.

$models = [
    'fuel_station' => Station::class, 'fuel_product' => Product::class, 'fuel_tank' => Tank::class, 'fuel_pump' => Pump::class, 'fuel_nozzle' => Nozzle::class,
    'fuel_price' => Price::class, 'fuel_shift' => Shift::class, 'fuel_settlement' => Settlement::class, 'fuel_reset' => MeterReset::class,
    'fuel_reconciliation' => Reconciliation::class, 'fuel_delivery' => Delivery::class, 'fuel_vehicle' => Vehicle::class, 'fuel_voucher' => Voucher::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

// FUEL-22: a paired till uploads its shifts and meter reset requests.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:fuel'])->group(function () {
    Route::post('fuel/device/shifts', [DeviceController::class, 'shifts'])->middleware('throttle:device-sync');
    Route::post('fuel/device/meter-resets', [DeviceController::class, 'meterResets'])->middleware('throttle:device-sync');
});

// The back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:fuel'])->group(function () {
    // FUEL-03
    Route::get('companies/{company}/fuel-settings', [SettingsController::class, 'show']);
    Route::put('companies/{company}/fuel-settings', [SettingsController::class, 'update']);

    // FUEL-02, FUEL-04 to FUEL-07
    Route::get('fuel/stations', [SetupController::class, 'stations']);
    Route::post('fuel/stations', [SetupController::class, 'storeStation']);
    Route::patch('fuel/stations/{fuel_station}', [SetupController::class, 'updateStation']);
    Route::post('fuel/stations/{fuel_station}/archive', [SetupController::class, 'archiveStation']);
    Route::get('fuel/products', [SetupController::class, 'products']);
    Route::post('fuel/products', [SetupController::class, 'storeProduct']);
    Route::patch('fuel/products/{fuel_product}', [SetupController::class, 'updateProduct']);
    Route::post('fuel/products/{fuel_product}/archive', [SetupController::class, 'archiveProduct']);
    Route::get('fuel/tanks', [SetupController::class, 'tanks']);
    Route::post('fuel/tanks', [SetupController::class, 'storeTank']);
    Route::get('fuel/tanks/{fuel_tank}', [SetupController::class, 'showTank']);
    Route::patch('fuel/tanks/{fuel_tank}', [SetupController::class, 'updateTank']);
    Route::post('fuel/tanks/{fuel_tank}/archive', [SetupController::class, 'archiveTank']);
    Route::get('fuel/tanks/{fuel_tank}/strapping', [SetupController::class, 'strapping']);
    Route::post('fuel/tanks/{fuel_tank}/strapping', [SetupController::class, 'storeStrapping']);
    Route::get('fuel/tanks/{fuel_tank}/dip-convert', [SetupController::class, 'dipConvert']);
    Route::get('fuel/pumps', [SetupController::class, 'pumps']);
    Route::post('fuel/pumps', [SetupController::class, 'storePump']);
    Route::patch('fuel/pumps/{fuel_pump}', [SetupController::class, 'updatePump']);
    Route::post('fuel/pumps/{fuel_pump}/archive', [SetupController::class, 'archivePump']);
    Route::get('fuel/nozzles', [SetupController::class, 'nozzles']);
    Route::post('fuel/nozzles', [SetupController::class, 'storeNozzle']);
    Route::patch('fuel/nozzles/{fuel_nozzle}', [SetupController::class, 'updateNozzle']);
    Route::post('fuel/nozzles/{fuel_nozzle}/archive', [SetupController::class, 'archiveNozzle']);

    // FUEL-08
    Route::get('fuel/prices', [PriceController::class, 'index']);
    Route::post('fuel/prices', [PriceController::class, 'store']);
    Route::post('fuel/prices/{fuel_price}/archive', [PriceController::class, 'archive']);

    // FUEL-09 to FUEL-15, FUEL-18
    Route::get('fuel/shifts', [ShiftController::class, 'shifts']);
    Route::get('fuel/shifts/{fuel_shift}', [ShiftController::class, 'showShift']);
    Route::get('fuel/settlements', [ShiftController::class, 'settlements']);
    Route::get('fuel/settlements/{fuel_settlement}', [ShiftController::class, 'showSettlement']);
    Route::get('fuel/sales', [ShiftController::class, 'sales']);

    // FUEL-17
    Route::get('fuel/meter-resets', [MeterResetController::class, 'index']);
    Route::post('fuel/meter-resets', [MeterResetController::class, 'store']);
    Route::post('fuel/meter-resets/{fuel_reset}/approve', [MeterResetController::class, 'approve']);
    Route::post('fuel/meter-resets/{fuel_reset}/reject', [MeterResetController::class, 'reject']);

    // FUEL-23 to FUEL-26
    Route::get('fuel/dips', [TankController::class, 'dips']);
    Route::post('fuel/dips', [TankController::class, 'storeDip']);
    Route::get('fuel/reconciliations', [TankController::class, 'reconciliations']);
    Route::get('fuel/reconciliations/{fuel_reconciliation}', [TankController::class, 'showReconciliation']);
    Route::get('fuel/deliveries', [TankController::class, 'deliveries']);
    Route::post('fuel/deliveries', [TankController::class, 'storeDelivery']);
    Route::get('fuel/deliveries/{fuel_delivery}', [TankController::class, 'showDelivery']);

    // FUEL-20, FUEL-21
    Route::get('fuel/vehicles', [FleetController::class, 'vehicles']);
    Route::post('fuel/vehicles', [FleetController::class, 'storeVehicle']);
    Route::patch('fuel/vehicles/{fuel_vehicle}', [FleetController::class, 'updateVehicle']);
    Route::post('fuel/vehicles/{fuel_vehicle}/archive', [FleetController::class, 'archiveVehicle']);
    Route::get('fuel/vouchers', [FleetController::class, 'vouchers']);
    Route::post('fuel/vouchers', [FleetController::class, 'storeVoucher']);
    Route::get('fuel/vouchers/{fuel_voucher}', [FleetController::class, 'showVoucher']);
    Route::post('fuel/vouchers/{fuel_voucher}/void', [FleetController::class, 'voidVoucher']);

    // FUEL-34, FUEL-38
    Route::get('fuel/reports/shift-summary', [ReportController::class, 'shiftSummary']);
    Route::get('fuel/reports/attendant-variance', [ReportController::class, 'attendantVariance']);
    Route::get('fuel/reports/nozzle-meter-history', [ReportController::class, 'nozzleMeterHistory']);
    Route::get('fuel/reports/tank-reconciliation', [ReportController::class, 'tankReconciliation']);
    Route::get('fuel/reports/sales-by-product', [ReportController::class, 'salesByProduct']);
    Route::get('fuel/reports/fleet-statement', [ReportController::class, 'fleetStatement']);
});
