<?php

use App\Modules\Leave\Http\Controllers\LeaveAdminController;
use App\Modules\Leave\Http\Controllers\LeaveReportController;
use App\Modules\Leave\Http\Controllers\LeaveRequestController;
use App\Modules\Leave\Http\Controllers\LeaveTypeController;
use Illuminate\Support\Facades\Route;

Route::prefix('leave')->group(function () {
    // Leave types
    Route::get('types', [LeaveTypeController::class, 'index']);
    Route::middleware('permission:leave.manage')->group(function () {
        Route::post('types', [LeaveTypeController::class, 'store']);
        Route::put('types/{leaveType}', [LeaveTypeController::class, 'update']);
        Route::delete('types/{leaveType}', [LeaveTypeController::class, 'destroy']);
    });

    // Employee self-service
    Route::middleware('permission:leave.apply')->group(function () {
        Route::get('my-requests', [LeaveRequestController::class, 'mine']);
        Route::get('my-balances', [LeaveRequestController::class, 'balances']);
        Route::post('preview', [LeaveRequestController::class, 'preview']);
        Route::post('requests', [LeaveRequestController::class, 'store']);
        Route::put('requests/{leaveRequest}', [LeaveRequestController::class, 'update']);
        Route::post('requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
    });

    // Handover answers: the colleague, the supervisor it escalated to (colleague without a usable login), or a
    // leave admin when nobody reachable is assigned (LeaveWorkflow::handoverSlotForUser decides).
    Route::middleware('permission:leave.apply|leave.approve|leave.manage')->group(function () {
        Route::get('handover-requests', [LeaveRequestController::class, 'handoverRequests']);
        Route::post('requests/{leaveRequest}/handover-decide', [LeaveRequestController::class, 'handoverDecide']);
        // Approvals-inbox endpoints (HandoverApprovalProvider).
        Route::post('requests/{leaveRequest}/handover/approve', [LeaveRequestController::class, 'handoverApprove']);
        Route::post('requests/{leaveRequest}/handover/reject', [LeaveRequestController::class, 'handoverReject']);
        // Recount for an amendment (handover colleague or approver; canView checked).
        Route::post('requests/{leaveRequest}/preview', [LeaveAdminController::class, 'previewFor']);
    });

    Route::get('requests/{leaveRequest}/document', [LeaveRequestController::class, 'document']);

    // Supervisor / HR approval, and team views (scoped to direct reports unless leave.manage / leave.reports)
    Route::middleware('permission:leave.approve|leave.manage')->group(function () {
        Route::get('approvals', [LeaveRequestController::class, 'approvals']);
        Route::post('requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        Route::post('requests/{leaveRequest}/amend-approve', [LeaveRequestController::class, 'amendAndApprove']);
        Route::post('requests/{leaveRequest}/recall', [LeaveRequestController::class, 'recall']);

        Route::get('on-leave', [LeaveAdminController::class, 'onLeave']);
        Route::get('calendar', [LeaveAdminController::class, 'calendar']);
        Route::get('planner', [LeaveRequestController::class, 'planner']);
        Route::get('recalls', [LeaveAdminController::class, 'recalls']);
        Route::post('recalls', [LeaveAdminController::class, 'storeRecall']);
        Route::get('counts', [LeaveAdminController::class, 'counts']);
        Route::get('employees/{employee}/summary', [LeaveAdminController::class, 'employeeSummary']);
    });

    // HR management
    Route::middleware('permission:leave.manage')->group(function () {
        Route::get('requests', [LeaveRequestController::class, 'all']);
        Route::get('on-leave-today', [LeaveRequestController::class, 'onLeaveToday']);
        Route::get('balances', [LeaveRequestController::class, 'allBalances']);
        Route::post('balances/adjust', [LeaveAdminController::class, 'adjust']);
        Route::post('balances/upload', [LeaveAdminController::class, 'upload']);
        Route::post('balances/initialise', [LeaveAdminController::class, 'initialise']);
        Route::post('balances/reconcile', [LeaveAdminController::class, 'reconcile']);
        Route::post('balances/{balanceId}/adjust', [LeaveRequestController::class, 'adjustBalance'])->whereNumber('balanceId');
        Route::get('balances/{balanceId}/transactions', [LeaveAdminController::class, 'transactions'])->whereNumber('balanceId');
        Route::get('runs', [LeaveAdminController::class, 'runs']);

        // Older paths kept for existing callers.
        Route::post('upload', [LeaveAdminController::class, 'upload']);
        Route::post('employees/{employee}/initialise', [LeaveAdminController::class, 'initialise']);
    });

    // Reports
    Route::middleware('permission:leave.reports')->group(function () {
        Route::get('reports', [LeaveReportController::class, 'index']);
        Route::get('reports/export', [LeaveReportController::class, 'export']);
    });

    Route::middleware('permission:leave.approve')->group(function () {
        Route::get('team-on-leave', [LeaveRequestController::class, 'onLeaveToday']);
    });
});
