<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use App\Core\Tenancy\Http\EnsureDeviceToken;
use Illuminate\Support\Facades\Route;
use Modules\HR\Http\Controllers\AttendanceController;
use Modules\HR\Http\Controllers\ContractController;
use Modules\HR\Http\Controllers\DocumentController;
use Modules\HR\Http\Controllers\EmployeeController;
use Modules\HR\Http\Controllers\LeaveController;
use Modules\HR\Http\Controllers\PayAccountController;
use Modules\HR\Http\Controllers\PositionController;
use Modules\HR\Http\Controllers\RecordsController;
use Modules\HR\Http\Controllers\RosterController;
use Modules\HR\Http\Controllers\SelfServiceController;
use Modules\HR\Http\Middleware\EnsureAttendanceDeviceToken;
use Modules\HR\Models;

// HR module routes (docs/modules/hr.md), prefix /api/v1, loaded by
// HrServiceProvider. RBAC-08: every route is closed (403 `module_inactive`)
// unless the tenant has the HR module active.

$models = [
    'hr_employee' => Models\Employee::class,
    'hr_pay_account' => Models\PayAccount::class,
    'hr_contract' => Models\Contract::class,
    'hr_document' => Models\Document::class,
    'hr_position' => Models\Position::class,
    'hr_leave_type' => Models\LeaveType::class,
    'hr_leave_request' => Models\LeaveRequest::class,
    'hr_shift' => Models\Shift::class,
    'hr_rotation' => Models\Rotation::class,
    'hr_roster' => Models\Roster::class,
    'hr_roster_shift' => Models\RosterShift::class,
    'hr_shift_swap' => Models\ShiftSwap::class,
    'hr_time_rule' => Models\TimeRule::class,
    'hr_punch' => Models\Punch::class,
    'hr_attendance_period' => Models\AttendancePeriod::class,
    'hr_attendance_device' => Models\AttendanceDevice::class,
    'hr_checklist_template' => Models\ChecklistTemplate::class,
    'hr_checklist_item' => Models\ChecklistItem::class,
    'hr_disciplinary_case' => Models\DisciplinaryCase::class,
    'hr_appraisal_cycle' => Models\AppraisalCycle::class,
    'hr_appraisal' => Models\Appraisal::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

// HR-27: a document behind a temporary signed URL bound to one user (DocumentController::download).
Route::get('hr-files/{path}', [DocumentController::class, 'download'])->where('path', 'tenants/.+')->middleware(['throttle:media', 'signed'])->name('hr.document.file');

// HR-23: a paired POS device's clock-ins (device token).
Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureDeviceToken::class, 'module:hr'])->group(function () {
    Route::post('hr/till/punches', [AttendanceController::class, 'tillPunches'])->middleware('throttle:device-sync');
});

// HR-24: a biometric attendance device's punches (its own token).
Route::middleware(['auth:sanctum', 'tenant', EnsureAttendanceDeviceToken::class, 'module:hr'])->group(function () {
    Route::post('hr/device/punches', [AttendanceController::class, 'devicePunches'])->middleware('throttle:device-sync');
});

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:hr'])->group(function () {
    // HR-02 to HR-07, HR-23, HR-27, HR-28: employees and what hangs off them.
    Route::get('hr/employees', [EmployeeController::class, 'index']);
    Route::post('hr/employees', [EmployeeController::class, 'store']);
    Route::get('hr/employees/{hr_employee}', [EmployeeController::class, 'show']);
    Route::patch('hr/employees/{hr_employee}', [EmployeeController::class, 'update']);
    Route::post('hr/employees/{hr_employee}/terminate', [EmployeeController::class, 'terminate']);
    Route::post('hr/employees/{hr_employee}/rehire', [EmployeeController::class, 'rehire']);
    Route::put('hr/employees/{hr_employee}/identifiers', [EmployeeController::class, 'identifiers']);
    Route::put('hr/employees/{hr_employee}/clock-pin', [EmployeeController::class, 'setClockPin']);
    Route::post('hr/employees/{hr_employee}/clock-pin/clear', [EmployeeController::class, 'clearClockPin']);
    Route::get('hr/employees/{hr_employee}/pay-accounts', [PayAccountController::class, 'index']);
    Route::post('hr/employees/{hr_employee}/pay-accounts', [PayAccountController::class, 'store']);
    Route::patch('hr/pay-accounts/{hr_pay_account}', [PayAccountController::class, 'update']);
    Route::post('hr/pay-accounts/{hr_pay_account}/archive', [PayAccountController::class, 'archive']);
    Route::get('hr/employees/{hr_employee}/contracts', [ContractController::class, 'index']);
    Route::post('hr/employees/{hr_employee}/contracts', [ContractController::class, 'store']);
    Route::patch('hr/contracts/{hr_contract}', [ContractController::class, 'update']);
    Route::post('hr/contracts/{hr_contract}/end', [ContractController::class, 'end']);
    Route::get('hr/employees/{hr_employee}/documents', [DocumentController::class, 'index']);
    Route::post('hr/employees/{hr_employee}/documents', [DocumentController::class, 'store'])->middleware('throttle:custom-field-files');
    Route::get('hr/documents/{hr_document}/link', [DocumentController::class, 'link']);
    Route::post('hr/documents/{hr_document}/archive', [DocumentController::class, 'archive']);
    Route::post('hr/employees/{hr_employee}/checklists', [RecordsController::class, 'startChecklist']);

    // HR-08: positions.
    Route::get('hr/positions', [PositionController::class, 'index']);
    Route::post('hr/positions', [PositionController::class, 'store']);
    Route::patch('hr/positions/{hr_position}', [PositionController::class, 'update']);
    Route::post('hr/positions/{hr_position}/archive', [PositionController::class, 'archive']);
    Route::post('hr/positions/{hr_position}/restore', [PositionController::class, 'restore']);

    // HR-12 to HR-15: leave.
    Route::get('hr/leave-types', [LeaveController::class, 'types']);
    Route::post('hr/leave-types', [LeaveController::class, 'storeType']);
    Route::post('hr/leave-types/apply-pack', [LeaveController::class, 'applyPack']);
    Route::patch('hr/leave-types/{hr_leave_type}', [LeaveController::class, 'updateType']);
    Route::post('hr/leave-types/{hr_leave_type}/archive', [LeaveController::class, 'archiveType']);
    Route::get('hr/leave-balances', [LeaveController::class, 'balances']);
    Route::post('hr/leave-adjustments', [LeaveController::class, 'adjust']);
    Route::post('hr/leave-accruals/run', [LeaveController::class, 'runAccruals']);
    Route::get('hr/leave-requests', [LeaveController::class, 'index']);
    Route::post('hr/leave-requests', [LeaveController::class, 'store']);
    Route::get('hr/leave-requests/{hr_leave_request}', [LeaveController::class, 'show']);
    Route::post('hr/leave-requests/{hr_leave_request}/cancel', [LeaveController::class, 'cancel']);
    Route::get('hr/leave-calendar', [LeaveController::class, 'calendar']);

    // HR-16 to HR-19: shifts, rotations, rosters, planned shifts and swaps.
    Route::get('hr/shifts', [RosterController::class, 'shifts']);
    Route::post('hr/shifts', [RosterController::class, 'storeShift']);
    Route::patch('hr/shifts/{hr_shift}', [RosterController::class, 'updateShift']);
    Route::post('hr/shifts/{hr_shift}/archive', [RosterController::class, 'archiveShift']);
    Route::get('hr/rotations', [RosterController::class, 'rotations']);
    Route::post('hr/rotations', [RosterController::class, 'storeRotation']);
    Route::patch('hr/rotations/{hr_rotation}', [RosterController::class, 'updateRotation']);
    Route::post('hr/rotations/{hr_rotation}/archive', [RosterController::class, 'archiveRotation']);
    Route::get('hr/rosters', [RosterController::class, 'rosters']);
    Route::post('hr/rosters', [RosterController::class, 'storeRoster']);
    Route::get('hr/rosters/{hr_roster}', [RosterController::class, 'showRoster']);
    Route::patch('hr/rosters/{hr_roster}', [RosterController::class, 'updateRoster']);
    Route::put('hr/rosters/{hr_roster}/members', [RosterController::class, 'members']);
    Route::post('hr/rosters/{hr_roster}/generate', [RosterController::class, 'generate']);
    Route::post('hr/rosters/{hr_roster}/archive', [RosterController::class, 'archiveRoster']);
    Route::get('hr/roster-shifts', [RosterController::class, 'plannedShifts']);
    Route::post('hr/roster-shifts', [RosterController::class, 'storePlanned']);
    Route::patch('hr/roster-shifts/{hr_roster_shift}', [RosterController::class, 'updatePlanned']);
    Route::post('hr/roster-shifts/{hr_roster_shift}/cancel', [RosterController::class, 'cancelPlanned']);
    Route::get('hr/shift-swaps', [RosterController::class, 'swaps']);
    Route::post('hr/shift-swaps', [RosterController::class, 'storeSwap']);
    Route::get('hr/shift-swaps/{hr_shift_swap}', [RosterController::class, 'showSwap']);
    Route::post('hr/shift-swaps/{hr_shift_swap}/accept', [RosterController::class, 'acceptSwap']);
    Route::post('hr/shift-swaps/{hr_shift_swap}/decline', [RosterController::class, 'declineSwap']);
    Route::post('hr/shift-swaps/{hr_shift_swap}/cancel', [RosterController::class, 'cancelSwap']);

    // HR-20 to HR-26: time rules, punches, timesheets, periods and devices.
    Route::get('hr/time-rules', [AttendanceController::class, 'timeRules']);
    Route::post('hr/time-rules', [AttendanceController::class, 'storeTimeRule']);
    Route::patch('hr/time-rules/{hr_time_rule}', [AttendanceController::class, 'updateTimeRule']);
    Route::get('hr/punches', [AttendanceController::class, 'punches']);
    Route::post('hr/punches', [AttendanceController::class, 'storePunch']);
    Route::post('hr/punches/{hr_punch}/void', [AttendanceController::class, 'voidPunch']);
    Route::get('hr/timesheets', [AttendanceController::class, 'timesheets']);
    Route::post('hr/timesheets/approve', [AttendanceController::class, 'approveDays']);
    Route::post('hr/timesheets/reopen', [AttendanceController::class, 'reopenDays']);
    Route::post('hr/timesheets/recompute', [AttendanceController::class, 'recompute']);
    Route::get('hr/attendance-periods', [AttendanceController::class, 'periods']);
    Route::post('hr/attendance-periods', [AttendanceController::class, 'storePeriod']);
    Route::get('hr/attendance-periods/{hr_attendance_period}', [AttendanceController::class, 'showPeriod']);
    Route::post('hr/attendance-periods/{hr_attendance_period}/close', [AttendanceController::class, 'closePeriod']);
    Route::get('hr/attendance-devices', [AttendanceController::class, 'devices']);
    Route::post('hr/attendance-devices', [AttendanceController::class, 'storeDevice']);
    Route::post('hr/attendance-devices/{hr_attendance_device}/token', [AttendanceController::class, 'rotateToken']);
    Route::post('hr/attendance-devices/{hr_attendance_device}/suspend', [AttendanceController::class, 'suspendDevice']);
    Route::post('hr/attendance-devices/{hr_attendance_device}/resume', [AttendanceController::class, 'resumeDevice']);
    Route::post('hr/attendance-devices/{hr_attendance_device}/retire', [AttendanceController::class, 'retireDevice']);

    // HR-28 to HR-30: checklists, disciplinary cases and appraisals.
    Route::get('hr/checklist-templates', [RecordsController::class, 'templates']);
    Route::post('hr/checklist-templates', [RecordsController::class, 'storeTemplate']);
    Route::patch('hr/checklist-templates/{hr_checklist_template}', [RecordsController::class, 'updateTemplate']);
    Route::post('hr/checklist-templates/{hr_checklist_template}/archive', [RecordsController::class, 'archiveTemplate']);
    Route::get('hr/checklists', [RecordsController::class, 'checklists']);
    Route::post('hr/checklist-items/{hr_checklist_item}/complete', [RecordsController::class, 'completeItem']);
    Route::post('hr/checklist-items/{hr_checklist_item}/reopen', [RecordsController::class, 'reopenItem']);
    Route::get('hr/disciplinary-cases', [RecordsController::class, 'cases']);
    Route::post('hr/disciplinary-cases', [RecordsController::class, 'storeCase']);
    Route::get('hr/disciplinary-cases/{hr_disciplinary_case}', [RecordsController::class, 'showCase']);
    Route::patch('hr/disciplinary-cases/{hr_disciplinary_case}', [RecordsController::class, 'updateCase']);
    Route::post('hr/disciplinary-cases/{hr_disciplinary_case}/close', [RecordsController::class, 'closeCase']);
    Route::get('hr/appraisal-cycles', [RecordsController::class, 'cycles']);
    Route::post('hr/appraisal-cycles', [RecordsController::class, 'storeCycle']);
    Route::patch('hr/appraisal-cycles/{hr_appraisal_cycle}', [RecordsController::class, 'updateCycle']);
    Route::post('hr/appraisal-cycles/{hr_appraisal_cycle}/open', [RecordsController::class, 'openCycle']);
    Route::post('hr/appraisal-cycles/{hr_appraisal_cycle}/close', [RecordsController::class, 'closeCycle']);
    Route::get('hr/appraisals', [RecordsController::class, 'appraisals']);
    Route::get('hr/appraisals/{hr_appraisal}', [RecordsController::class, 'showAppraisal']);
    Route::patch('hr/appraisals/{hr_appraisal}', [RecordsController::class, 'updateAppraisal']);
    Route::post('hr/appraisals/{hr_appraisal}/complete', [RecordsController::class, 'completeAppraisal']);

    // HR-31: employee self-service.
    Route::get('hr/me', [SelfServiceController::class, 'show']);
    Route::patch('hr/me', [SelfServiceController::class, 'update']);
    Route::get('hr/me/leave', [SelfServiceController::class, 'leave']);
    Route::post('hr/me/leave-requests', [SelfServiceController::class, 'requestLeave']);
    Route::post('hr/me/leave-requests/{hr_leave_request}/cancel', [SelfServiceController::class, 'cancelLeave']);
    Route::get('hr/me/roster', [SelfServiceController::class, 'roster']);
    Route::get('hr/me/attendance', [SelfServiceController::class, 'attendance']);
    Route::post('hr/me/clock', [SelfServiceController::class, 'clock']);
    Route::get('hr/me/swaps', [SelfServiceController::class, 'swaps']);
    Route::get('hr/me/swap-peers', [SelfServiceController::class, 'swapPeers']);
    Route::post('hr/me/swaps', [SelfServiceController::class, 'requestSwap']);
    Route::post('hr/me/swaps/{hr_shift_swap}/accept', [SelfServiceController::class, 'acceptSwap']);
    Route::post('hr/me/swaps/{hr_shift_swap}/decline', [SelfServiceController::class, 'declineSwap']);
    Route::get('hr/me/appraisals', [SelfServiceController::class, 'appraisals']);
    Route::patch('hr/me/appraisals/{hr_appraisal}', [SelfServiceController::class, 'updateAppraisal']);
    Route::post('hr/me/appraisals/{hr_appraisal}/acknowledge', [SelfServiceController::class, 'acknowledgeAppraisal']);
});
