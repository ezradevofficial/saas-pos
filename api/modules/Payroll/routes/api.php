<?php

use App\Core\Identity\Http\Middleware\EnsureFullAccessToken;
use App\Core\Identity\Http\Middleware\EnsureUserToken;
use App\Core\Localisation\Http\ApplyTenantLocale;
use Illuminate\Support\Facades\Route;
use Modules\Payroll\Http\Controllers\EmployeeController;
use Modules\Payroll\Http\Controllers\LoanController;
use Modules\Payroll\Http\Controllers\PayslipController;
use Modules\Payroll\Http\Controllers\ReturnController;
use Modules\Payroll\Http\Controllers\RunController;
use Modules\Payroll\Http\Controllers\SetupController;
use Modules\Payroll\Models;

// Payroll module routes (docs/modules/payroll.md), prefix /api/v1, loaded
// by PayrollServiceProvider. RBAC-08: every route is closed (403
// `module_inactive`) unless the tenant has the Payroll module active.

$models = [
    'payroll_pay_group' => Models\PayGroup::class,
    'payroll_item' => Models\Item::class,
    'payroll_employee' => Models\PayrollEmployee::class,
    'payroll_employee_item' => Models\EmployeeItem::class,
    'payroll_loan' => Models\Loan::class,
    'payroll_run' => Models\Run::class,
    'payroll_payslip' => Models\Payslip::class,
    'payroll_return' => Models\StatutoryReturn::class,
];

foreach ($models as $parameter => $model) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
    Route::model($parameter, $model);
}

Route::middleware(['auth:sanctum', 'tenant', ApplyTenantLocale::class, EnsureUserToken::class, EnsureFullAccessToken::class, 'module:payroll'])->prefix('payroll')->name('payroll.')->group(function () {
    // PAY-02, PAY-08: settings and the statutory pack of a company.
    Route::get('settings/{company}', [SetupController::class, 'settings'])->name('settings.show');
    Route::put('settings/{company}', [SetupController::class, 'updateSettings'])->name('settings.update');
    Route::get('statutory/{company}', [SetupController::class, 'statutory'])->name('statutory');

    // PAY-03, PAY-04.
    Route::get('pay-groups', [SetupController::class, 'payGroups'])->name('pay-groups.index');
    Route::post('pay-groups', [SetupController::class, 'storePayGroup'])->name('pay-groups.store');
    Route::patch('pay-groups/{payroll_pay_group}', [SetupController::class, 'updatePayGroup'])->name('pay-groups.update');
    Route::post('pay-groups/{payroll_pay_group}/archive', [SetupController::class, 'archivePayGroup'])->name('pay-groups.archive');
    Route::get('items', [SetupController::class, 'items'])->name('items.index');
    Route::post('items', [SetupController::class, 'storeItem'])->name('items.store');
    Route::patch('items/{payroll_item}', [SetupController::class, 'updateItem'])->name('items.update');
    Route::post('items/{payroll_item}/archive', [SetupController::class, 'archiveItem'])->name('items.archive');

    // PAY-05, PAY-06.
    Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees/sync', [EmployeeController::class, 'sync'])->name('employees.sync');
    Route::get('employees/{payroll_employee}', [EmployeeController::class, 'show'])->name('employees.show');
    Route::patch('employees/{payroll_employee}', [EmployeeController::class, 'update'])->name('employees.update');
    Route::get('employees/{payroll_employee}/items', [EmployeeController::class, 'items'])->name('employees.items');
    Route::post('employees/{payroll_employee}/items', [EmployeeController::class, 'storeItem'])->name('employees.items.store');
    Route::patch('employee-items/{payroll_employee_item}', [EmployeeController::class, 'updateItem'])->name('employee-items.update');
    Route::post('employee-items/{payroll_employee_item}/archive', [EmployeeController::class, 'archiveItem'])->name('employee-items.archive');

    // PAY-13.
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
    Route::get('loans/{payroll_loan}', [LoanController::class, 'show'])->name('loans.show');
    Route::patch('loans/{payroll_loan}', [LoanController::class, 'update'])->name('loans.update');
    Route::post('loans/{payroll_loan}/settle', [LoanController::class, 'settle'])->name('loans.settle');
    Route::post('loans/{payroll_loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');

    // PAY-14 to PAY-20.
    Route::get('payment-formats', [RunController::class, 'formats'])->name('payment-formats');
    Route::get('runs', [RunController::class, 'index'])->name('runs.index');
    Route::post('runs', [RunController::class, 'store'])->name('runs.store');
    Route::get('runs/{payroll_run}', [RunController::class, 'show'])->name('runs.show');
    Route::get('runs/{payroll_run}/lines', [RunController::class, 'lines'])->name('runs.lines');
    Route::post('runs/{payroll_run}/calculate', [RunController::class, 'calculate'])->name('runs.calculate');
    Route::post('runs/{payroll_run}/submit', [RunController::class, 'submit'])->name('runs.submit');
    Route::post('runs/{payroll_run}/cancel', [RunController::class, 'cancel'])->name('runs.cancel');
    Route::post('runs/{payroll_run}/post', [RunController::class, 'post'])->name('runs.post');
    Route::post('runs/{payroll_run}/corrections', [RunController::class, 'correction'])->name('runs.corrections');
    Route::get('runs/{payroll_run}/payment-file', [RunController::class, 'paymentFile'])->middleware('throttle:payroll-files')->name('runs.payment-file');
    Route::get('runs/{payroll_run}/payment-summary', [RunController::class, 'paymentSummary'])->middleware('throttle:payroll-files')->name('runs.payment-summary');

    // PAY-19.
    Route::get('payslips', [PayslipController::class, 'index'])->name('payslips.index');
    Route::get('payslips/{payroll_payslip}', [PayslipController::class, 'show'])->name('payslips.show');
    Route::get('payslips/{payroll_payslip}/pdf', [PayslipController::class, 'pdf'])->middleware('throttle:payroll-files')->name('payslips.pdf');

    // PAY-24: "My payslips" (the linked employee, no permission needed).
    Route::get('me/payslips', [PayslipController::class, 'index'])->name('me.payslips.index');
    Route::get('me/payslips/{payroll_payslip}', [PayslipController::class, 'show'])->name('me.payslips.show');
    Route::get('me/payslips/{payroll_payslip}/pdf', [PayslipController::class, 'pdf'])->middleware('throttle:payroll-files')->name('me.payslips.pdf');

    // PAY-21.
    Route::get('returns/types', [ReturnController::class, 'types'])->name('returns.types');
    Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
    Route::post('returns', [ReturnController::class, 'store'])->name('returns.store');
    Route::get('returns/{payroll_return}/file', [ReturnController::class, 'file'])->middleware('throttle:payroll-files')->name('returns.file');
});
