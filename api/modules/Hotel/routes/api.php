<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\Hotel\Http\Controllers\AvailabilityController;
use Modules\Hotel\Http\Controllers\FolioController;
use Modules\Hotel\Http\Controllers\GuestController;
use Modules\Hotel\Http\Controllers\HousekeepingController;
use Modules\Hotel\Http\Controllers\NightAuditController;
use Modules\Hotel\Http\Controllers\RatePlanController;
use Modules\Hotel\Http\Controllers\ReportController;
use Modules\Hotel\Http\Controllers\ReservationController;
use Modules\Hotel\Http\Controllers\RoomChargeController;
use Modules\Hotel\Http\Controllers\RoomController;
use Modules\Hotel\Http\Controllers\RoomTypeController;
use Modules\Hotel\Http\Controllers\SettingsController;
use Modules\Hotel\Http\Controllers\StayController;
use Modules\Hotel\Models\ChargeException;
use Modules\Hotel\Models\Folio;
use Modules\Hotel\Models\FolioLine;
use Modules\Hotel\Models\Guest;
use Modules\Hotel\Models\HousekeepingTask;
use Modules\Hotel\Models\NightAuditRun;
use Modules\Hotel\Models\Payment;
use Modules\Hotel\Models\RatePlan;
use Modules\Hotel\Models\Reservation;
use Modules\Hotel\Models\Room;
use Modules\Hotel\Models\RoomType;
use Modules\Hotel\Models\Stay;

// Hotel module routes (docs/modules/hotel.md), prefix /api/v1, loaded by
// HotelServiceProvider. RBAC-08: every route is closed (403 `module_inactive`)
// unless the tenant has the module active. A paired till uses one route of its
// own (the room lookup); the rest is people's tokens, a housekeeper's phone included.

$models = [
    'hotel_room_type' => RoomType::class, 'hotel_room' => Room::class, 'hotel_rate_plan' => RatePlan::class, 'hotel_guest' => Guest::class,
    'hotel_reservation' => Reservation::class, 'hotel_stay' => Stay::class, 'hotel_folio' => Folio::class, 'hotel_line' => FolioLine::class,
    'hotel_payment' => Payment::class, 'hotel_task' => HousekeepingTask::class, 'hotel_audit' => NightAuditRun::class, 'hotel_exception' => ChargeException::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

// HOT-22, HOT-35: a paired till asks, online, about a room of its own property.
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:hotel'])->group(function () {
    Route::post('hotel/device/room-lookup', [RoomChargeController::class, 'deviceLookup'])->middleware('throttle:device-sync');
});

// The back office and the housekeeper's phone (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:hotel'])->group(function () {
    // HOT-02
    Route::get('companies/{company}/hotel-settings', [SettingsController::class, 'show']);
    Route::put('companies/{company}/hotel-settings', [SettingsController::class, 'update']);

    // HOT-03 to HOT-05
    Route::get('hotel/room-types', [RoomTypeController::class, 'index']);
    Route::post('hotel/room-types', [RoomTypeController::class, 'store']);
    Route::patch('hotel/room-types/{hotel_room_type}', [RoomTypeController::class, 'update']);
    Route::post('hotel/room-types/{hotel_room_type}/archive', [RoomTypeController::class, 'archive']);
    Route::get('hotel/rooms', [RoomController::class, 'index']);
    Route::post('hotel/rooms', [RoomController::class, 'store']);
    Route::patch('hotel/rooms/{hotel_room}', [RoomController::class, 'update']);
    Route::post('hotel/rooms/{hotel_room}/archive', [RoomController::class, 'archive']);
    Route::post('hotel/rooms/{hotel_room}/status', [RoomController::class, 'status']);

    // HOT-06 to HOT-10
    Route::get('hotel/rate-plans', [RatePlanController::class, 'index']);
    Route::post('hotel/rate-plans', [RatePlanController::class, 'store']);
    Route::get('hotel/rate-plans/{hotel_rate_plan}', [RatePlanController::class, 'show']);
    Route::patch('hotel/rate-plans/{hotel_rate_plan}', [RatePlanController::class, 'update']);
    Route::post('hotel/rate-plans/{hotel_rate_plan}/archive', [RatePlanController::class, 'archive']);
    Route::get('hotel/rate-quote', [RatePlanController::class, 'quote']);

    // HOT-11
    Route::get('hotel/availability', AvailabilityController::class);

    // HOT-16
    Route::get('hotel/guests', [GuestController::class, 'index']);
    Route::post('hotel/guests', [GuestController::class, 'store']);
    Route::get('hotel/guests/{hotel_guest}', [GuestController::class, 'show']);
    Route::patch('hotel/guests/{hotel_guest}', [GuestController::class, 'update']);

    // HOT-12 to HOT-19, HOT-37
    Route::get('hotel/reservations', [ReservationController::class, 'index']);
    Route::post('hotel/reservations', [ReservationController::class, 'store']);
    Route::get('hotel/reservations/{hotel_reservation}', [ReservationController::class, 'show']);
    Route::post('hotel/walk-ins', [ReservationController::class, 'walkIn']);
    Route::patch('hotel/stays/{hotel_stay}', [StayController::class, 'update']);
    Route::post('hotel/stays/{hotel_stay}/assign-room', [StayController::class, 'assignRoom']);
    Route::post('hotel/stays/{hotel_stay}/check-in', [StayController::class, 'checkIn']);
    Route::post('hotel/stays/{hotel_stay}/check-out', [StayController::class, 'checkOut']);
    Route::post('hotel/stays/{hotel_stay}/move-room', [StayController::class, 'moveRoom']);
    Route::post('hotel/stays/{hotel_stay}/cancel', [StayController::class, 'cancel']);
    Route::post('hotel/stays/{hotel_stay}/no-show', [StayController::class, 'noShow']);

    // HOT-19 to HOT-27
    Route::get('hotel/folios', [FolioController::class, 'index']);
    Route::get('hotel/folios/{hotel_folio}', [FolioController::class, 'show']);
    Route::get('hotel/folios/{hotel_folio}/print', [FolioController::class, 'print']);
    Route::post('hotel/folios/{hotel_folio}/charges', [FolioController::class, 'charge']);
    Route::post('hotel/folios/{hotel_folio}/payments', [FolioController::class, 'pay']);
    Route::post('hotel/folios/{hotel_folio}/payments/{hotel_payment}/check', [FolioController::class, 'checkPayment']);
    Route::post('hotel/folios/{hotel_folio}/refunds', [FolioController::class, 'refund']);
    Route::post('hotel/folios/{hotel_folio}/transfers', [FolioController::class, 'transfer']);
    Route::post('hotel/folios/{hotel_folio}/lines/{hotel_line}/void', [FolioController::class, 'void']);
    Route::post('hotel/folios/{hotel_folio}/settle', [FolioController::class, 'settle']);

    // HOT-22, HOT-23
    Route::get('hotel/room-charges/lookup', [RoomChargeController::class, 'lookup']);
    Route::get('hotel/charge-exceptions', [RoomChargeController::class, 'exceptions']);
    Route::post('hotel/charge-exceptions/{hotel_exception}/resolve', [RoomChargeController::class, 'resolve']);

    // HOT-28, HOT-29
    Route::get('hotel/housekeeping/board', [HousekeepingController::class, 'board']);
    Route::get('hotel/housekeeping/tasks', [HousekeepingController::class, 'index']);
    Route::post('hotel/housekeeping/tasks', [HousekeepingController::class, 'store']);
    Route::get('hotel/housekeeping/my-tasks', [HousekeepingController::class, 'mine']);
    Route::post('hotel/housekeeping/tasks/{hotel_task}/assign', [HousekeepingController::class, 'assign']);
    Route::post('hotel/housekeeping/tasks/{hotel_task}/start', [HousekeepingController::class, 'start']);
    Route::post('hotel/housekeeping/tasks/{hotel_task}/finish', [HousekeepingController::class, 'finish']);
    Route::post('hotel/housekeeping/tasks/{hotel_task}/inspect', [HousekeepingController::class, 'inspect']);

    // HOT-30
    Route::get('hotel/night-audits', [NightAuditController::class, 'index']);
    Route::post('hotel/night-audits', [NightAuditController::class, 'store']);
    Route::get('hotel/night-audits/{hotel_audit}', [NightAuditController::class, 'show']);

    // HOT-31
    Route::get('hotel/reports/occupancy', [ReportController::class, 'occupancy']);
    Route::get('hotel/reports/arrivals', [ReportController::class, 'stays'])->defaults('report', 'arrivals');
    Route::get('hotel/reports/departures', [ReportController::class, 'stays'])->defaults('report', 'departures');
    Route::get('hotel/reports/in-house', [ReportController::class, 'stays'])->defaults('report', 'in-house');
    Route::get('hotel/reports/no-shows', [ReportController::class, 'stays'])->defaults('report', 'no-shows');
    Route::get('hotel/reports/folio-balances', [FolioController::class, 'balances']);
    Route::get('hotel/reports/housekeeping', [HousekeepingController::class, 'report']);
});
