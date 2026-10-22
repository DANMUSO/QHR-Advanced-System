<?php

use App\Modules\Employees\Http\Controllers\EmployeeController;
use App\Modules\Employees\Http\Controllers\ContractController;
use App\Modules\Employees\Http\Controllers\EmployeeLifecycleController;
use App\Modules\Employees\Http\Controllers\EmployeeAccountController;
use App\Modules\Employees\Http\Controllers\EmployeeMediaController;
use App\Modules\Employees\Http\Controllers\KycController;
use App\Modules\Employees\Http\Controllers\LetterController;
use App\Modules\Employees\Http\Controllers\StaffReportController;
use App\Modules\Employees\Http\Controllers\SelfServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('profile')->group(function () {
    Route::get('/', [EmployeeController::class, 'myProfile']);
    Route::put('/', [EmployeeController::class, 'updateProfile']);
    Route::post('photo', [EmployeeController::class, 'uploadPhoto']);
    Route::post('cover', fn (\Illuminate\Http\Request $r) => app(EmployeeMediaController::class)->uploadOwn($r, 'cover'));

    // Self-service requests (decided by HR under /staff-requests)
    Route::get('requests', [SelfServiceController::class, 'mine']);
    Route::post('change-requests', [SelfServiceController::class, 'requestChange']);
    Route::post('change-requests/{id}/cancel', [SelfServiceController::class, 'cancelChange'])->whereNumber('id');
    Route::get('contracts', [SelfServiceController::class, 'contracts']);
    Route::post('resignations', [SelfServiceController::class, 'resign']);
    Route::post('resignations/{id}/withdraw', [SelfServiceController::class, 'withdrawResignation'])->whereNumber('id');
    Route::get('resignations/{id}/letter', [SelfServiceController::class, 'resignationLetter'])->whereNumber('id');
    Route::post('letter-requests', [SelfServiceController::class, 'requestLetter']);
    Route::post('letter-requests/{id}/cancel', [SelfServiceController::class, 'cancelLetter'])->whereNumber('id');
});

// HR queue for self-service requests (ProfileChangeRequests.vue)
Route::prefix('staff-requests')->middleware('permission:employees.update')->group(function () {
    Route::get('summary', [SelfServiceController::class, 'summary']);
    Route::get('profile-changes', [SelfServiceController::class, 'profileChanges']);
    Route::post('profile-changes/{id}/approve', [SelfServiceController::class, 'approveChange'])->whereNumber('id');
    Route::post('profile-changes/{id}/reject', [SelfServiceController::class, 'rejectChange'])->whereNumber('id');
    Route::get('resignations', [SelfServiceController::class, 'resignations']);
    Route::post('resignations/{id}/reject', [SelfServiceController::class, 'declineResignation'])->whereNumber('id');
    Route::get('letters', [SelfServiceController::class, 'letterRequests']);
    Route::post('letters/{id}/approve', [SelfServiceController::class, 'issueLetter'])->whereNumber('id');
    Route::post('letters/{id}/reject', [SelfServiceController::class, 'rejectLetter'])->whereNumber('id');
});

Route::prefix('employees')->group(function () {
    Route::get('meta', [EmployeeController::class, 'meta']);
    Route::get('/', [EmployeeController::class, 'index']);
    Route::get('export', [EmployeeController::class, 'export']);
    Route::get('stats', [EmployeeController::class, 'stats']);
    Route::get('org-chart', [EmployeeController::class, 'orgChart']);
    Route::get('next-number', [EmployeeController::class, 'nextNumber']);

    // Letters & contracts: HR lists (employees.view) and issues/edits (employees.update). Downloads stay open to the
    // employee who owns the document (checked in the controller).
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('letter-templates', [LetterController::class, 'templates']);
        Route::get('letters', [LetterController::class, 'index']);
        Route::get('contracts', [ContractController::class, 'index']);
    });
    Route::middleware('permission:employees.update')->group(function () {
        Route::post('letters', [LetterController::class, 'store']);
        Route::post('contracts', [ContractController::class, 'store']);
        Route::put('contracts/{contract}', [ContractController::class, 'update'])->whereNumber('contract');
    });
    Route::get('letters/{letter}/download', [LetterController::class, 'download']);
    Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf']);

    // Transfers & exits
    Route::middleware('permission:employees.view')->group(function () {
        Route::get('transfers', [EmployeeLifecycleController::class, 'transfers']);
        Route::get('exits', [EmployeeLifecycleController::class, 'exits']);
    });
    Route::middleware('permission:employees.update')->group(function () {
        Route::post('transfers', [EmployeeLifecycleController::class, 'storeTransfer']);
        Route::delete('transfers/{transfer}', [EmployeeLifecycleController::class, 'cancelTransfer'])->whereNumber('transfer');
    });
    Route::get('transfer-options', [EmployeeLifecycleController::class, 'transferOptions'])->middleware('permission:employees.transfer');
    Route::middleware('permission:employees.exit')->group(function () {
        Route::post('exits', [EmployeeLifecycleController::class, 'storeExit']);
        Route::put('exits/{exit}', [EmployeeLifecycleController::class, 'updateExit'])->whereNumber('exit');
        Route::post('exits/{exit}/cancel', [EmployeeLifecycleController::class, 'cancelExit'])->whereNumber('exit');
    });

    // KYC
    Route::middleware('permission:employees.update')->group(function () {
        Route::get('kyc', [KycController::class, 'index']);
        Route::get('kyc/{employee}', [KycController::class, 'show']);
        Route::post('{employee}/kyc/verify', [KycController::class, 'verify']);
    });

    // Staff reports
    Route::get('reports', [StaffReportController::class, 'index'])->middleware('permission:employees.view');

    // Per-employee
    Route::get('{employee}', [EmployeeController::class, 'show'])->whereNumber('employee');
    Route::get('{employee}/history', [EmployeeController::class, 'history'])->whereNumber('employee');
    Route::post('/', [EmployeeController::class, 'store']);
    Route::put('{employee}', [EmployeeController::class, 'update'])->whereNumber('employee');
    Route::post('{employee}/exit', [EmployeeLifecycleController::class, 'storeExit'])->whereNumber('employee')->middleware('permission:employees.exit');
    Route::post('{employee}/reinstate', [EmployeeLifecycleController::class, 'reinstate'])->whereNumber('employee')->middleware('permission:employees.exit');
    Route::post('{employee}/transfer', [EmployeeLifecycleController::class, 'transferCompany'])->whereNumber('employee')->middleware('permission:employees.transfer');

    // Account management
    Route::middleware('permission:employees.update')->group(function () {
        Route::post('{employee}/account', [EmployeeAccountController::class, 'create'])->whereNumber('employee');
        Route::post('{employee}/account/resend', [EmployeeAccountController::class, 'resend'])->whereNumber('employee');
        Route::post('accounts/bulk-create', [EmployeeAccountController::class, 'bulkCreate']);
        Route::post('accounts/bulk-resend', [EmployeeAccountController::class, 'bulkResend']);
    });

    // Media
    Route::post('{employee}/media', [EmployeeMediaController::class, 'upload'])->whereNumber('employee');
    Route::delete('{employee}/media/{media}', [EmployeeMediaController::class, 'destroy'])->whereNumber('employee');
});
