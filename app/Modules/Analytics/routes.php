<?php

use App\Modules\Analytics\Http\Controllers\AnalyticsController as A;
use Illuminate\Support\Facades\Route;

// Already inside the auth:sanctum + account.secure group, under /api.
Route::prefix('analytics')->middleware('permission:dashboard.hr')->group(function () {
    Route::get('hr-dashboard', [A::class, 'dashboard']);
    Route::get('ai-insights', [A::class, 'insights']);
    Route::post('ai-insights/refresh', [A::class, 'refreshInsights']);
    $reports = 'headcount|payroll-cost|absenteeism|pay-gap|attrition|workforce';
    Route::get('{report}', [A::class, 'report'])->where('report', $reports);
    Route::get('{report}/export', [A::class, 'export'])->where('report', $reports);
});
