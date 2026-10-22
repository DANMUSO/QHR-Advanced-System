<?php

use App\Modules\Engagement\Http\Controllers\EngagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('engagement')->group(function () {
    // Self-service: my comments (including my anonymous ones) and follow-ups in my own threads.
    Route::get('my-comments', [EngagementController::class, 'mine']);
    Route::get('comments/mine', [EngagementController::class, 'mine']);
    Route::post('comments', [EngagementController::class, 'store']);
    Route::post('comments/{engagementComment}/respond', [EngagementController::class, 'respond'])->whereNumber('engagementComment');

    Route::middleware('permission:engagement.manage')->group(function () {
        Route::get('comments', [EngagementController::class, 'index']);
        Route::post('comments/{engagementComment}/reply', [EngagementController::class, 'reply'])->whereNumber('engagementComment');
    });
});
