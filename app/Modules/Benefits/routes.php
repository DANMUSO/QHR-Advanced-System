<?php

use App\Modules\Benefits\Http\Controllers\BenefitController;
use App\Modules\Benefits\Http\Controllers\ExpenseController;
use App\Modules\Benefits\Http\Controllers\ExpensePaymentController;
use App\Modules\Benefits\Http\Controllers\TravelApprovalController;
use Illuminate\Support\Facades\Route;

// Staff expenses, travel and salary advances (self-service; approvals checked in the controller).
Route::prefix('expenses')->group(function () {
    Route::get('/', [ExpenseController::class, 'mine']);
    Route::post('/', [ExpenseController::class, 'store']);
    Route::get('travel', [ExpenseController::class, 'travel']);
    Route::post('travel', [ExpenseController::class, 'storeTravel']);
    Route::get('advance', [ExpenseController::class, 'advances']);
    Route::post('advance', [ExpenseController::class, 'storeAdvance']);
    Route::get('approvals', [ExpenseController::class, 'approvals']);
    Route::post('{id}/approve', [ExpenseController::class, 'approve'])->whereNumber('id');
    Route::post('{id}/reject', [ExpenseController::class, 'reject'])->whereNumber('id');

    // Travel approvals: supervisor (or HR fallback); authorised in the controller.
    Route::get('travel/approvals', [TravelApprovalController::class, 'index']);
    Route::post('travel/{id}/approve', [TravelApprovalController::class, 'approve'])->whereNumber('id');
    Route::post('travel/{id}/reject', [TravelApprovalController::class, 'reject'])->whereNumber('id');

    // Finance payment step for approved expense claims.
    Route::middleware('permission:claims.pay')->group(function () {
        Route::get('payments', [ExpensePaymentController::class, 'index']);
        Route::post('{id}/pay', [ExpensePaymentController::class, 'pay'])->whereNumber('id');
    });
});

// Benefits Administration (BenefitsHub.vue). Same gate as the Vue route (settings.manage) plus organization.manage,
// which already manages the benefit catalogue in Organization > Structure.
Route::prefix('benefits')->middleware('permission:settings.manage|organization.manage')->group(function () {
    Route::get('/', [BenefitController::class, 'index']);
    Route::post('/', [BenefitController::class, 'store']);
    Route::get('pension', [BenefitController::class, 'pension']);
    Route::post('pension', [BenefitController::class, 'storePension']);
    Route::put('pension/{member}', [BenefitController::class, 'updatePension'])->whereNumber('member');
    Route::delete('pension/{member}', [BenefitController::class, 'destroyPension'])->whereNumber('member');
    Route::put('{benefit}', [BenefitController::class, 'update'])->whereNumber('benefit');
    Route::delete('{benefit}', [BenefitController::class, 'destroy'])->whereNumber('benefit');
});
