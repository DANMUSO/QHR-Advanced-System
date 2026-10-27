<?php

use App\Modules\Organization\Http\Controllers\AutomationController;
use App\Modules\Organization\Http\Controllers\BrandingController;
use App\Modules\Organization\Http\Controllers\CompanyController;
use App\Modules\Organization\Http\Controllers\DataController;
use App\Modules\Organization\Http\Controllers\IntegrationController;
use App\Modules\Organization\Http\Controllers\RoleController;
use App\Modules\Organization\Http\Controllers\SettingsController;
use App\Modules\Organization\Http\Controllers\StructureController;
use App\Modules\Organization\Http\Controllers\SupportController;
use App\Modules\Organization\Http\Controllers\UserAdminController;
use Illuminate\Support\Facades\Route;

// Companies
Route::prefix('organization')->group(function () {
    Route::middleware('permission:organization.manage|companies.manage')->group(function () {
        Route::get('companies', [CompanyController::class, 'index']);
        Route::get('companies/{company}', [CompanyController::class, 'show'])->whereNumber('company');
        Route::put('companies/{company}', [CompanyController::class, 'update'])->whereNumber('company');
        Route::post('companies/{company}/logo', [CompanyController::class, 'uploadLogo'])->whereNumber('company');
        Route::delete('companies/{company}/logo', [CompanyController::class, 'deleteLogo'])->whereNumber('company');
    });

    Route::middleware('permission:companies.manage')->group(function () {
        Route::post('companies', [CompanyController::class, 'store']);
        Route::post('companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])->whereNumber('company');
        Route::delete('companies/{company}', [CompanyController::class, 'destroy'])->whereNumber('company');
    });

    // Structure
    Route::middleware('permission:organization.manage')->group(function () {
        Route::get('summary', [StructureController::class, 'summary']);
        Route::get('{type}', [StructureController::class, 'index'])->whereIn('type', StructureController::TYPES);
        Route::post('{type}', [StructureController::class, 'store'])->whereIn('type', StructureController::TYPES);
        Route::put('{type}/{id}', [StructureController::class, 'update'])->whereIn('type', StructureController::TYPES)->whereNumber('id');
        Route::delete('{type}/{id}', [StructureController::class, 'destroy'])->whereIn('type', StructureController::TYPES)->whereNumber('id');
    });
});

// Settings
Route::middleware('permission:settings.manage')->prefix('admin')->group(function () {
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);
});

// Users & roles
Route::middleware('permission:users.manage')->prefix('admin')->group(function () {
    Route::get('users', [UserAdminController::class, 'index']);
    Route::post('users', [UserAdminController::class, 'store']);
    Route::get('users/{user}', [UserAdminController::class, 'show'])->whereNumber('user');
    Route::put('users/{user}/roles', [UserAdminController::class, 'syncRoles'])->whereNumber('user');
    Route::put('users/{user}/permissions', [UserAdminController::class, 'syncPermissions'])->whereNumber('user');
    Route::post('users/{user}/disable', [UserAdminController::class, 'disable'])->whereNumber('user');
    Route::post('users/{user}/enable', [UserAdminController::class, 'enable'])->whereNumber('user');
    Route::post('users/{user}/unlock', [UserAdminController::class, 'unlock'])->whereNumber('user');
    Route::post('users/{user}/reset-two-factor', [UserAdminController::class, 'resetTwoFactor'])->whereNumber('user');
    Route::post('users/{user}/resend-activation', [UserAdminController::class, 'resendActivation'])->whereNumber('user');
    Route::post('users/{user}/activation-link', [UserAdminController::class, 'activationLink'])->whereNumber('user');
    Route::post('users/{user}/force-password-change', [UserAdminController::class, 'forcePasswordChange'])->whereNumber('user');
    Route::get('roles', [RoleController::class, 'index']);
    Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
});

// Branding & localisation
Route::middleware('permission:settings.manage')->group(function () {
    Route::get('organization/branding', [BrandingController::class, 'show']);
    Route::put('organization/branding', [BrandingController::class, 'update']);
    Route::post('organization/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('organization/branding/logo', [BrandingController::class, 'deleteLogo']);

    // Workflows & reminders
    Route::get('organization/workflows', [AutomationController::class, 'workflows']);
    Route::post('organization/workflows', [AutomationController::class, 'storeWorkflow']);
    Route::put('organization/workflows/{workflow}', [AutomationController::class, 'updateWorkflow']);
    Route::patch('organization/workflows/{workflow}/toggle', [AutomationController::class, 'toggleWorkflow']);
    Route::delete('organization/workflows/{workflow}', [AutomationController::class, 'destroyWorkflow']);
    Route::get('organization/reminders', [AutomationController::class, 'reminders']);
    Route::post('organization/reminders', [AutomationController::class, 'storeReminder']);
    Route::put('organization/reminders/{reminder}', [AutomationController::class, 'updateReminder']);
    Route::delete('organization/reminders/{reminder}', [AutomationController::class, 'destroyReminder']);
    Route::post('organization/reminders/{reminder}/run', [AutomationController::class, 'runReminder']);

    // Integrations, API tokens, webhooks
    Route::get('organization/integrations', [IntegrationController::class, 'index']);
    Route::patch('organization/integrations/{key}/toggle', [IntegrationController::class, 'toggle']);
    Route::put('organization/integrations/{key}/config', [IntegrationController::class, 'config']);
    Route::get('organization/api-tokens', [IntegrationController::class, 'tokens']);
    Route::post('organization/api-tokens', [IntegrationController::class, 'createToken']);
    Route::delete('organization/api-tokens/{token}', [IntegrationController::class, 'revokeToken']);
    Route::get('organization/webhooks', [IntegrationController::class, 'webhooks']);
    Route::post('organization/webhooks', [IntegrationController::class, 'storeWebhook']);
    Route::put('organization/webhooks/{webhook}', [IntegrationController::class, 'updateWebhook']);
    Route::delete('organization/webhooks/{webhook}', [IntegrationController::class, 'destroyWebhook']);
    Route::post('organization/webhooks/{webhook}/test', [IntegrationController::class, 'testWebhook']);

    // Data, imports, backups
    Route::get('organization/imports', [DataController::class, 'imports']);
    Route::post('organization/imports', [DataController::class, 'import']);
    Route::get('organization/imports/template/{type}', [DataController::class, 'template']);
    Route::get('organization/exports/{type}', [DataController::class, 'export']);
    Route::get('organization/backups', [DataController::class, 'backups']);
    Route::post('organization/backups', [DataController::class, 'createBackup']);
    Route::get('organization/backups/{backup}/download', [DataController::class, 'downloadBackup']);
    Route::delete('organization/backups/{backup}', [DataController::class, 'destroyBackup']);
});

// Support tickets: every signed-in user raises and follows their own; settings.manage sees the company's (see SupportController::visible).
Route::get('organization/support', [SupportController::class, 'index']);
Route::post('organization/support', [SupportController::class, 'store']);
Route::get('organization/support/{ticket}', [SupportController::class, 'show']);
Route::post('organization/support/{ticket}/reply', [SupportController::class, 'reply']);
Route::put('organization/support/{ticket}/status', [SupportController::class, 'updateStatus']);
