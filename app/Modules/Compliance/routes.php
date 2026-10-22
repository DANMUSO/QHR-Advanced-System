<?php

use App\Modules\Compliance\Http\Controllers\ComplianceController;
use App\Modules\Compliance\Http\Controllers\ExitClearanceController;
use App\Modules\Compliance\Http\Controllers\MyGrievanceController;
use App\Modules\Employees\Http\Controllers\KycController;
use Illuminate\Support\Facades\Route;

/*
 * Compliance registers, one route set for every register ({type}). Permissions are checked per register
 * in ComplianceController::authorizeType(): data-protection needs settings.manage, the rest employees.update.
 */
Route::prefix('compliance')->group(function () {
    // KYC status lives with the employee record; reuse the Employees module listing.
    Route::get('kyc', [KycController::class, 'index'])->middleware('permission:employees.update');

    // Exit clearance board: departments sign off each clearance item.
    Route::middleware('permission:employees.exit')->group(function () {
        Route::get('exits', [ExitClearanceController::class, 'index']);
        Route::post('exits/{employee}/clearance/{item}', [ExitClearanceController::class, 'signOff'])
            ->whereNumber('employee')->where('item', '[a-z_]+');
    });

    // Generate / fetch the Employees "warning" letter linked to a warning (permission checked in the controller).
    Route::post('warnings/{id}/letter', [ComplianceController::class, 'warningLetter'])->whereNumber('id');

    $types = 'disciplinary|grievances|warnings|health-safety|incidents|data-protection|crb|medical';
    Route::get('{type}', [ComplianceController::class, 'index'])->where('type', $types);
    Route::post('{type}', [ComplianceController::class, 'store'])->where('type', $types);
    Route::get('{type}/{id}', [ComplianceController::class, 'show'])->where('type', $types)->whereNumber('id');
    Route::put('{type}/{id}', [ComplianceController::class, 'update'])->where('type', $types)->whereNumber('id');
    Route::delete('{type}/{id}', [ComplianceController::class, 'destroy'])->where('type', $types)->whereNumber('id');
});

// Employee self-service grievances (any signed-in employee; scoped to the caller in the controller).
Route::get('my/grievances', [MyGrievanceController::class, 'index']);
Route::post('my/grievances', [MyGrievanceController::class, 'store']);
