<?php

use App\Modules\Probation\Http\Controllers\ProbationController;
use Illuminate\Support\Facades\Route;

// Review stages (configured Workflow 'probation_end', else Primary → Secondary → HOO) are decided by the person or role
// each stage resolves to, in order (ProbationRouting::canDecide); HR cannot act for a reviewer.
Route::prefix('probation')->group(function () {
    Route::get('my-reviews', [ProbationController::class, 'myReviews']);
    Route::post('reviews/{probationReview}/review', [ProbationController::class, 'stageReview']);
    // Legacy per-stage paths: must match the review's current stage.
    Route::post('reviews/{probationReview}/{stage}', [ProbationController::class, 'stageReview'])->whereIn('stage', ['primary', 'secondary', 'hoo']);
});

Route::prefix('probation')->middleware('permission:probation.manage')->group(function () {
    Route::get('/', [ProbationController::class, 'index']);
    Route::get('employees/{employee}', [ProbationController::class, 'show']);
    Route::post('reviews/{probationReview}/hr-decision', [ProbationController::class, 'hrDecision']);
});
