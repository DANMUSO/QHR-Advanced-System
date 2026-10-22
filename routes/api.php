<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ImpersonationController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SecurityController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Authentication (guest)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('two-factor-challenge', [AuthController::class, 'twoFactorChallenge'])->name('two-factor');
    Route::post('forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1')->name('forgot');
    Route::post('reset-password', [PasswordController::class, 'reset'])->middleware('throttle:10,1')->name('reset');
    Route::post('activate', [PasswordController::class, 'activate'])->middleware('throttle:10,1')->name('activate');
});

/*
|--------------------------------------------------------------------------
| Account security
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->prefix('auth')->name('auth.')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('me');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
    Route::put('password', [PasswordController::class, 'change'])->name('password.change');
    Route::post('two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
    Route::post('two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
    Route::delete('two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    Route::post('two-factor/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('two-factor.recovery');
    Route::post('impersonate/stop', [ImpersonationController::class, 'stop'])->name('impersonate.stop');
});

/*
|--------------------------------------------------------------------------
| Application
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'account.secure'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard/widgets/{key}', [DashboardController::class, 'show']);
    Route::put('me/preferences', [PreferenceController::class, 'update']);
    Route::get('search', SearchController::class)->middleware('throttle:60,1');
    Route::get('lookups', \App\Http\Controllers\LookupController::class);

    Route::get('account/sessions', [SecurityController::class, 'sessions']);
    Route::delete('account/sessions/{id}', [SecurityController::class, 'destroySession']);
    Route::post('account/sessions/logout-others', [SecurityController::class, 'destroyOtherSessions']);
    Route::get('account/login-history', [SecurityController::class, 'loginHistory']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('announcements', [AnnouncementController::class, 'index']);
    Route::get('announcements/admin', [AnnouncementController::class, 'index'])->middleware('permission:announcements.manage');
    Route::post('announcements/{announcement}/read', [AnnouncementController::class, 'markRead']);
    Route::middleware('permission:announcements.manage')->group(function () {
        Route::post('announcements', [AnnouncementController::class, 'store']);
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);
    });

    Route::post('admin/impersonate/{user}', [ImpersonationController::class, 'start'])->middleware('permission:users.impersonate');
    Route::middleware('permission:audit.view')->prefix('admin')->group(function () {
        Route::get('login-activities', [AuditController::class, 'loginActivities']);
        Route::get('activity-log', [AuditController::class, 'activityLog']);
    });

    // Each HR module registers its endpoints in app/Modules/<Name>/routes.php.
    foreach (\App\Support\ModuleRegistry::routeFiles() as $moduleRoutes) {
        require $moduleRoutes;
    }

});
