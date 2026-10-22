<?php

use App\Modules\Claims\Http\Controllers\ApprovalController;
use App\Modules\Claims\Http\Controllers\ClaimController;
use App\Modules\Claims\Http\Controllers\PaymentController;
use App\Modules\Claims\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;

// Already inside auth:sanctum + account.secure group.

Route::prefix('claims')->group(function () {
    // ── Fixed paths first (must come before {claim} wildcard) ──────────────

    // Employee self-service (non-wildcard)
    Route::middleware('permission:claims.submit')->group(function () {
        Route::get('summary', [ClaimController::class, 'summary']);
        Route::get('mine', [ClaimController::class, 'mine']);
        Route::get('/', [ClaimController::class, 'mine']); // alias: MyPortal calls GET /claims
        Route::post('/', [ClaimController::class, 'store']);
    });

    // HR approval queue. No permission gate: a claim can be routed (or escalated, ApproverResolver) to an employee
    // without claims.approve; ApprovalController::queueFor / canHrDecide do the authorisation.
    Route::get('approvals', [ApprovalController::class, 'index']);

    // Finance payment queue (non-wildcard)
    Route::middleware('permission:claims.pay')->group(function () {
        Route::get('payments', [PaymentController::class, 'index']);
        Route::get('payment-schedule', [PaymentController::class, 'schedule']);
        Route::post('bulk-pay', [PaymentController::class, 'bulkPay']); // before {claim} so it is not shadowed
    });

    // Claim settings: approvers (§1.9) and per-employee limit overrides (§4.2)
    Route::middleware('permission:settings.manage|claims.approve')->group(function () {
        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings/approvers', [SettingsController::class, 'updateApprovers']);
        Route::get('limits', [SettingsController::class, 'limits']);
        Route::put('limits/{employee}', [SettingsController::class, 'updateLimit'])->whereNumber('employee');
    });

    // Finance / HR reports (non-wildcard)
    Route::middleware('permission:claims.reports')->group(function () {
        Route::get('reports', [\App\Modules\Claims\Http\Controllers\ReportController::class, 'index']);
        Route::get('reports/export', [\App\Modules\Claims\Http\Controllers\ReportController::class, 'export']);
    });

    // ── Wildcard {claim} routes (must come after all fixed paths) ───────────

    Route::get('{claim}', [ClaimController::class, 'show']);
    Route::get('{claim}/receipts/{receipt}', [ClaimController::class, 'receipt']);

    Route::middleware('permission:claims.submit')->group(function () {
        Route::put('{claim}', [ClaimController::class, 'update']);
        Route::post('{claim}/cancel', [ClaimController::class, 'cancel']);
        Route::delete('{claim}/receipts/{receipt}', [ClaimController::class, 'destroyReceipt']);
    });

    // Authorised in the controller (ClaimWorkflow::canHrDecide): the routed/escalated approver or claims.approve.
    Route::post('{claim}/approve', [ApprovalController::class, 'approve']);
    Route::post('{claim}/reject', [ApprovalController::class, 'reject']);

    Route::middleware('permission:claims.pay')->group(function () {
        Route::post('{claim}/pay', [PaymentController::class, 'pay']);
        Route::post('{claim}/finance-reject', [PaymentController::class, 'reject']);
    });

    // Payment confirmation PDF: Finance, HR and the claimant (authorised in the controller).
    Route::get('{claim}/confirmation', [PaymentController::class, 'confirmation']);
});
