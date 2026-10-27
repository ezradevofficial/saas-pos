<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\MasterData\Parties\Party;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\Salon\Http\Controllers\AppointmentController;
use Modules\Salon\Http\Controllers\AvailabilityController;
use Modules\Salon\Http\Controllers\ClientController;
use Modules\Salon\Http\Controllers\CommissionController;
use Modules\Salon\Http\Controllers\DeviceController;
use Modules\Salon\Http\Controllers\PackageController;
use Modules\Salon\Http\Controllers\QueueController;
use Modules\Salon\Http\Controllers\ReportController;
use Modules\Salon\Http\Controllers\ResourceController;
use Modules\Salon\Http\Controllers\ServiceController;
use Modules\Salon\Http\Controllers\SettingsController;
use Modules\Salon\Http\Controllers\StaffController;
use Modules\Salon\Models\Appointment;
use Modules\Salon\Models\CommissionBatch;
use Modules\Salon\Models\CommissionRule;
use Modules\Salon\Models\Package;
use Modules\Salon\Models\PackageType;
use Modules\Salon\Models\Payment;
use Modules\Salon\Models\QueueEntry;
use Modules\Salon\Models\Resource;
use Modules\Salon\Models\SalonException;
use Modules\Salon\Models\Service;
use Modules\Salon\Models\Staff;
use Modules\Salon\Models\TimeOff;

// Salon module routes (docs/modules/salon.md), prefix /api/v1, loaded by SalonServiceProvider. RBAC-08 (SAL-01): every
// route is closed (403 `module_inactive`) unless the tenant has the Salon module active. A route names the permission it
// needs with `->defaults('permission', ...)`; its request checks it at the place the request touches (RBAC-04).

foreach (['salon_service', 'salon_ptype', 'salon_staff', 'salon_resource', 'salon_appointment', 'salon_queue', 'salon_package', 'salon_exception', 'salon_rule', 'salon_batch', 'salon_payment', 'salon_timeoff', 'salon_client'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

Route::model('salon_service', Service::class);
Route::model('salon_ptype', PackageType::class);
Route::model('salon_staff', Staff::class);
Route::model('salon_resource', Resource::class);
Route::model('salon_appointment', Appointment::class);
Route::model('salon_queue', QueueEntry::class);
Route::model('salon_package', Package::class);
Route::model('salon_exception', SalonException::class);
Route::model('salon_rule', CommissionRule::class);
Route::model('salon_batch', CommissionBatch::class);
Route::model('salon_payment', Payment::class);
Route::model('salon_timeoff', TimeOff::class);
Route::model('salon_client', Party::class);

// SAL-29: what a paired salon till sends (device token only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:salon'])->group(function () {
    Route::post('salon/device/sync', [DeviceController::class, 'sync']);
});

// The back office (people's tokens only).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:salon'])->group(function () {
    // SAL-10, SAL-11, SAL-14: a company's settings.
    Route::get('salon/companies/{company}/settings', [SettingsController::class, 'show']);
    Route::put('salon/companies/{company}/settings', [SettingsController::class, 'update'])->defaults('permission', 'salon.settings.edit');

    // SAL-02, SAL-18: the service menu and package types.
    Route::get('salon/services', [ServiceController::class, 'index'])->defaults('permission', 'salon.service.view');
    Route::post('salon/services', [ServiceController::class, 'store'])->defaults('permission', 'salon.service.manage');
    Route::put('salon/services/{salon_service}', [ServiceController::class, 'update'])->defaults('permission', 'salon.service.manage');
    Route::post('salon/services/{salon_service}/archive', [ServiceController::class, 'archive'])->defaults('permission', 'salon.service.manage');
    Route::get('salon/service-items', [ServiceController::class, 'items'])->defaults('permission', 'salon.service.manage');
    Route::get('salon/package-types', [ServiceController::class, 'types'])->defaults('permission', 'salon.service.view');
    Route::post('salon/package-types', [ServiceController::class, 'storeType'])->defaults('permission', 'salon.service.manage');
    Route::put('salon/package-types/{salon_ptype}', [ServiceController::class, 'updateType'])->defaults('permission', 'salon.service.manage');
    Route::post('salon/package-types/{salon_ptype}/archive', [ServiceController::class, 'archiveType'])->defaults('permission', 'salon.service.manage');

    // SAL-03, SAL-04: staff, hours and time off.
    Route::get('salon/staff', [StaffController::class, 'index'])->defaults('permission', 'salon.staff.view');
    Route::post('salon/staff', [StaffController::class, 'store'])->defaults('permission', 'salon.staff.manage');
    Route::get('salon/staff/{salon_staff}', [StaffController::class, 'show'])->defaults('permission', 'salon.staff.view');
    Route::put('salon/staff/{salon_staff}', [StaffController::class, 'update'])->defaults('permission', 'salon.staff.manage');
    Route::post('salon/staff/{salon_staff}/archive', [StaffController::class, 'archive'])->defaults('permission', 'salon.staff.manage');
    Route::get('salon/staff/{salon_staff}/hours', [StaffController::class, 'hours'])->defaults('permission', 'salon.staff.view');
    Route::put('salon/staff/{salon_staff}/hours', [StaffController::class, 'setHours'])->defaults('permission', 'salon.staff.manage');
    Route::get('salon/staff/{salon_staff}/time-off', [StaffController::class, 'timeOff'])->defaults('permission', 'salon.staff.view');
    Route::post('salon/staff/{salon_staff}/time-off', [StaffController::class, 'storeTimeOff'])->defaults('permission', 'salon.staff.manage');
    Route::post('salon/time-off/{salon_timeoff}/cancel', [StaffController::class, 'cancelTimeOff'])->defaults('permission', 'salon.staff.manage');

    // SAL-05: resources.
    Route::get('salon/resources', [ResourceController::class, 'index'])->defaults('permission', 'salon.resource.view');
    Route::post('salon/resources', [ResourceController::class, 'store'])->defaults('permission', 'salon.resource.manage');
    Route::put('salon/resources/{salon_resource}', [ResourceController::class, 'update'])->defaults('permission', 'salon.resource.manage');
    Route::post('salon/resources/{salon_resource}/archive', [ResourceController::class, 'archive'])->defaults('permission', 'salon.resource.manage');

    // SAL-15: a branch's public booking page.
    Route::get('salon/branches/{branch}/online', [ResourceController::class, 'online'])->defaults('permission', 'salon.online.view');
    Route::put('salon/branches/{branch}/online', [ResourceController::class, 'updateOnline'])->defaults('permission', 'salon.online.manage');
    Route::post('salon/branches/{branch}/online/regenerate-code', [ResourceController::class, 'regenerateCode'])->defaults('permission', 'salon.online.manage');

    // SAL-06, SAL-07: open times and the calendar.
    Route::get('salon/availability', [AvailabilityController::class, 'availability'])->defaults('permission', 'salon.appointment.create');
    Route::get('salon/calendar', [AvailabilityController::class, 'calendar'])->defaults('permission', 'salon.appointment.view');

    // SAL-07 to SAL-13, SAL-16: appointments.
    Route::get('salon/appointments', [AppointmentController::class, 'index'])->defaults('permission', 'salon.appointment.view');
    Route::post('salon/appointments', [AppointmentController::class, 'store'])->defaults('permission', 'salon.appointment.create');
    Route::get('salon/appointments/{salon_appointment}', [AppointmentController::class, 'show'])->defaults('permission', 'salon.appointment.view');
    Route::post('salon/appointments/{salon_appointment}/move', [AppointmentController::class, 'move'])->defaults('permission', 'salon.appointment.edit');
    Route::post('salon/appointments/{salon_appointment}/check-in', [AppointmentController::class, 'checkIn'])->defaults('permission', 'salon.appointment.serve');
    Route::post('salon/appointments/{salon_appointment}/start', [AppointmentController::class, 'start'])->defaults('permission', 'salon.appointment.serve');
    Route::post('salon/appointments/{salon_appointment}/finish', [AppointmentController::class, 'finish'])->defaults('permission', 'salon.appointment.serve');
    Route::post('salon/appointments/{salon_appointment}/cancel', [AppointmentController::class, 'cancel'])->defaults('permission', 'salon.appointment.cancel');
    Route::post('salon/appointments/{salon_appointment}/no-show', [AppointmentController::class, 'noShow'])->defaults('permission', 'salon.appointment.cancel');
    Route::post('salon/appointments/{salon_appointment}/waive-fee', [AppointmentController::class, 'waiveFee'])->defaults('permission', 'salon.fee.waive');
    Route::put('salon/appointments/{salon_appointment}/notes', [AppointmentController::class, 'notes'])->defaults('permission', 'salon.client.notes');
    Route::get('salon/appointments/{salon_appointment}/checkout', [AppointmentController::class, 'checkout'])->defaults('permission', 'salon.appointment.view');
    Route::post('salon/appointments/{salon_appointment}/deposit', [AppointmentController::class, 'deposit'])->defaults('permission', 'salon.deposit.take');
    Route::post('salon/appointments/{salon_appointment}/deposit/refund', [AppointmentController::class, 'refundDeposit'])->defaults('permission', 'salon.deposit.refund');
    Route::get('salon/payments/{salon_payment}/check', [AppointmentController::class, 'checkPayment'])->defaults('permission', 'salon.deposit.take');

    // SAL-12: the walk-in queue.
    Route::get('salon/queue', [QueueController::class, 'index'])->defaults('permission', 'salon.queue.view');
    Route::post('salon/queue', [QueueController::class, 'store'])->defaults('permission', 'salon.queue.manage');
    Route::post('salon/queue/{salon_queue}/seat', [QueueController::class, 'seat'])->defaults('permission', 'salon.queue.manage');
    Route::post('salon/queue/{salon_queue}/leave', [QueueController::class, 'leave'])->defaults('permission', 'salon.queue.manage');

    // SAL-25: clients.
    Route::get('salon/clients', [ClientController::class, 'index'])->defaults('permission', 'salon.client.view');
    Route::post('salon/clients', [ClientController::class, 'store'])->defaults('permission', 'salon.client.manage');
    Route::get('salon/clients/{salon_client}', [ClientController::class, 'show'])->defaults('permission', 'salon.client.view');
    Route::put('salon/clients/{salon_client}', [ClientController::class, 'update'])->defaults('permission', 'salon.client.manage');
    Route::get('salon/clients/{salon_client}/visits', [ClientController::class, 'visits'])->defaults('permission', 'salon.client.view');

    // SAL-18: packages and the credit exceptions.
    Route::get('salon/packages', [PackageController::class, 'index'])->defaults('permission', 'salon.package.view');
    Route::get('salon/packages/{salon_package}', [PackageController::class, 'show'])->defaults('permission', 'salon.package.view');
    Route::post('salon/packages/{salon_package}/adjust', [PackageController::class, 'adjust'])->defaults('permission', 'salon.package.adjust');
    Route::get('salon/exceptions', [PackageController::class, 'exceptions'])->defaults('permission', 'salon.exception.view');
    Route::post('salon/exceptions/{salon_exception}/resolve', [PackageController::class, 'resolve'])->defaults('permission', 'salon.exception.resolve');

    // SAL-20 to SAL-24: commissions.
    Route::get('salon/commission-rules', [CommissionController::class, 'rules'])->defaults('permission', 'salon.commission.view');
    Route::post('salon/commission-rules', [CommissionController::class, 'storeRule'])->defaults('permission', 'salon.commission.rule');
    Route::put('salon/commission-rules/{salon_rule}', [CommissionController::class, 'updateRule'])->defaults('permission', 'salon.commission.rule');
    Route::post('salon/commission-rules/{salon_rule}/archive', [CommissionController::class, 'archiveRule'])->defaults('permission', 'salon.commission.rule');
    Route::get('salon/commissions', [CommissionController::class, 'entries'])->defaults('permission', 'salon.commission.view');
    Route::get('salon/tips', [CommissionController::class, 'tips'])->defaults('permission', 'salon.commission.view');
    Route::get('salon/commission-batches', [CommissionController::class, 'batchIndex'])->defaults('permission', 'salon.commission.view');
    Route::post('salon/commission-batches', [CommissionController::class, 'batchStore'])->defaults('permission', 'salon.commission.build');
    Route::get('salon/commission-batches/{salon_batch}', [CommissionController::class, 'batchShow'])->defaults('permission', 'salon.commission.view');
    Route::get('salon/commission-batches/{salon_batch}/csv', [CommissionController::class, 'csv'])->defaults('permission', 'salon.commission.view');
    Route::post('salon/commission-batches/{salon_batch}/submit', [CommissionController::class, 'submit'])->defaults('permission', 'salon.commission.build');
    Route::post('salon/commission-batches/{salon_batch}/approve', [CommissionController::class, 'approve'])->defaults('permission', 'salon.commission.approve');
    Route::post('salon/commission-batches/{salon_batch}/reject', [CommissionController::class, 'reject'])->defaults('permission', 'salon.commission.approve');
    Route::post('salon/commission-batches/{salon_batch}/export', [CommissionController::class, 'export'])->defaults('permission', 'salon.commission.export');

    // SAL-27: reports.
    foreach (['utilisation' => 'utilisation', 'services' => 'services', 'no-shows' => 'noShows', 'commissions' => 'commissions', 'liability' => 'liability'] as $path => $method) {
        Route::get("salon/reports/{$path}", [ReportController::class, $method])->defaults('permission', 'salon.report.view');
    }
});
