<?php

use App\Modules\Payroll\Http\Controllers\ComponentController;
use App\Modules\Payroll\Http\Controllers\LoanController;
use App\Modules\Payroll\Http\Controllers\OutputsController;
use App\Modules\Payroll\Http\Controllers\PayProfileController;
use App\Modules\Payroll\Http\Controllers\PayrollRunController;
use App\Modules\Payroll\Http\Controllers\PayslipController;
use App\Modules\Payroll\Http\Controllers\SetupController;
use App\Modules\Payroll\Http\Controllers\StructureController;
use Illuminate\Support\Facades\Route;

Route::prefix('payroll')->group(function () {
    // HR decides whether staff paid in a run may sign it off (small companies where every approver is on the payroll).
    Route::put('settings/own-pay-signoff', [SetupController::class, 'updateOwnPaySignoff'])->middleware('permission:settings.manage');

    // Employee self-service
    Route::middleware('permission:payroll.view-own')->group(function () {
        Route::get('my-payslips', [PayslipController::class, 'index']);
        Route::get('my-payslips/{line}', [PayslipController::class, 'show']);
        Route::get('my-payslips/{line}/pdf', [PayslipController::class, 'pdf']);
        Route::get('my-p9a/{year}', [PayslipController::class, 'myP9a']);
        Route::get('my-loans', [LoanController::class, 'myLoans']);
        Route::get('my-advances', [LoanController::class, 'myAdvances']);
        Route::post('advances/request', [LoanController::class, 'requestAdvance']);
        Route::post('advances/{advance}/cancel', [LoanController::class, 'cancelAdvance']);
    });

    // Viewing runs: preparers, approvers (must open a run to approve it) and report users (Outputs page: read + export only)
    Route::middleware('permission:payroll.manage|payroll.review|payroll.approve|payroll.reports')->group(function () {
        Route::get('runs', [PayrollRunController::class, 'index']);
        Route::get('runs/{run}', [PayrollRunController::class, 'show']);
        Route::get('runs/{run}/employees', [PayrollRunController::class, 'employees']);
        Route::get('runs/{run}/employees/{line}', [PayrollRunController::class, 'line']);
        Route::get('runs/{run}/export/{type}', [PayrollRunController::class, 'export']);
        Route::get('runs/{run}/employees/{line}/pdf', [PayrollRunController::class, 'linePdf']);

        // Outputs: payment files, statutory returns, variance
        Route::get('payment-files', [OutputsController::class, 'paymentFiles']);
        Route::get('payment-files/{file}/download', [OutputsController::class, 'downloadPaymentFile']);
        Route::get('returns', [OutputsController::class, 'returns']);
        Route::get('returns/export', [OutputsController::class, 'exportReturns']);
        Route::get('variance', [OutputsController::class, 'variance']);
        Route::get('variance/export', [OutputsController::class, 'exportVariance']);
    });

    // Pay profiles: view with employees.view-salary, edit with payroll.manage (controller checks can_edit)
    Route::middleware('permission:payroll.manage|employees.view-salary')->group(function () {
        Route::get('profiles', [PayProfileController::class, 'index']);
        Route::get('employees/{employee}/profile', [PayProfileController::class, 'show']);
    });

    // HR review of a calculated run (spec: Calculated → Reviewed (HR) → Approved (Finance))
    Route::post('runs/{run}/review', [PayrollRunController::class, 'review'])->middleware('permission:payroll.manage|payroll.review');

    // Salary advances: payroll officers and approvers both decide requests
    Route::middleware('permission:payroll.manage|payroll.approve')->group(function () {
        Route::get('advances', [LoanController::class, 'advances']);
        Route::post('advances/{advance}/approve', [LoanController::class, 'approveAdvance']);
        Route::post('advances/{advance}/reject', [LoanController::class, 'rejectAdvance']);
    });

    // Payroll management
    Route::middleware('permission:payroll.manage')->group(function () {
        // Runs
        Route::get('leavers', [PayrollRunController::class, 'leavers']);
        Route::post('runs', [PayrollRunController::class, 'store']);
        Route::post('runs/{run}/calculate', [PayrollRunController::class, 'calculate']);
        Route::post('runs/{run}/reopen', [PayrollRunController::class, 'reopen']);
        Route::delete('runs/{run}', [PayrollRunController::class, 'destroy']);
        Route::get('runs/{run}/adjustments', [PayrollRunController::class, 'adjustments']);
        Route::post('runs/{run}/adjustments', [PayrollRunController::class, 'storeAdjustment']);
        Route::put('runs/{run}/adjustments/{adjustment}', [PayrollRunController::class, 'updateAdjustment']);
        Route::delete('runs/{run}/adjustments/{adjustment}', [PayrollRunController::class, 'destroyAdjustment']);
        Route::post('runs/{run}/email-payslips', [PayrollRunController::class, 'emailPayslips']);

        // Pay profiles
        Route::put('employees/{employee}/profile', [PayProfileController::class, 'update']);
        Route::post('employees/{employee}/salary', [PayProfileController::class, 'changeSalary']);
        Route::post('employees/{employee}/components', [PayProfileController::class, 'storeComponent']);
        Route::put('employees/{employee}/components/{assignment}', [PayProfileController::class, 'updateComponent']);
        Route::delete('employees/{employee}/components/{assignment}', [PayProfileController::class, 'destroyComponent']);

        // Components
        Route::get('components', [ComponentController::class, 'index']);
        Route::post('components', [ComponentController::class, 'store']);
        Route::put('components/{component}', [ComponentController::class, 'update']);
        Route::delete('components/{component}', [ComponentController::class, 'destroy']);

        // Structures
        Route::get('structures', [StructureController::class, 'index']);
        Route::post('structures', [StructureController::class, 'store']);
        Route::put('structures/{structure}', [StructureController::class, 'update']);
        Route::delete('structures/{structure}', [StructureController::class, 'destroy']);
        Route::post('structures/{structure}/apply', [StructureController::class, 'apply']);

        // Loans
        Route::get('loans', [LoanController::class, 'index']);
        Route::post('loans', [LoanController::class, 'store']);
        Route::post('loans/preview', [LoanController::class, 'preview']);
        Route::get('loans/{loan}', [LoanController::class, 'show']);
        Route::post('loans/{loan}/pause', [LoanController::class, 'pause']);
        Route::post('loans/{loan}/resume', [LoanController::class, 'resume']);
        Route::post('loans/{loan}/settle', [LoanController::class, 'settle']);

        // Setup: statutory, calendar, settings, calculator preview
        Route::get('statutory', [SetupController::class, 'statutory']);
        Route::post('statutory', [SetupController::class, 'storeRate']);
        Route::put('statutory/{rate}', [SetupController::class, 'updateRate']);
        Route::delete('statutory/{rate}', [SetupController::class, 'destroyRate']);
        Route::get('calendar', [SetupController::class, 'calendar']);
        Route::post('calendar/generate', [SetupController::class, 'generateCalendar']);
        Route::put('calendar/{calendar}', [SetupController::class, 'updateCalendar']);
        Route::get('settings', [SetupController::class, 'settings']);
        Route::put('settings', [SetupController::class, 'updateSettings']);
        Route::post('preview', [SetupController::class, 'preview']);

        // P9A
        Route::get('p9a/{employee}/{year}', [PayslipController::class, 'p9a']);
    });

    // Approval
    Route::middleware('permission:payroll.approve')->group(function () {
        Route::post('runs/{run}/approve', [PayrollRunController::class, 'approve']);
        Route::post('runs/{run}/reject', [PayrollRunController::class, 'reject']);
        Route::post('runs/{run}/mark-paid', [PayrollRunController::class, 'markPaid']);
    });
});
