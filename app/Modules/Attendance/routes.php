<?php

use App\Modules\Attendance\Http\Controllers\DeviceController;
use App\Modules\Attendance\Http\Controllers\GeofenceController;
use App\Modules\Attendance\Http\Controllers\MyAttendanceController;
use App\Modules\Attendance\Http\Controllers\OvertimeController;
use App\Modules\Attendance\Http\Controllers\RegisterController;
use App\Modules\Attendance\Http\Controllers\ReportController;
use App\Modules\Attendance\Http\Controllers\SettingsController;
use App\Modules\Attendance\Http\Controllers\ShiftController;
use App\Modules\Attendance\Http\Controllers\TeamController;
use App\Modules\Attendance\Http\Controllers\TimesheetController;
use Illuminate\Support\Facades\Route;

// Already inside the auth:sanctum + account.secure group, under /api.
// The biometric device API (/api/attendance/device/*) is registered in module.php (token auth, no session).
Route::prefix('attendance')->group(function () {
    // Self-service
    Route::middleware('permission:attendance.view-own')->group(function () {
        Route::get('mine', [MyAttendanceController::class, 'mine']);
        Route::get('me/today', [MyAttendanceController::class, 'today']);
        Route::get('me/month', [MyAttendanceController::class, 'month']);
        Route::post('punch', [MyAttendanceController::class, 'punch'])->middleware('throttle:12,1');
        Route::post('timesheets/submit', [TimesheetController::class, 'submit']);
    });

    // Self, supervisors and HR (access checked per employee)
    Route::get('timesheets', [TimesheetController::class, 'index']);
    Route::get('employees/{employee}/month', [MyAttendanceController::class, 'employeeMonth']);

    // Supervisors (own team) and HR (company)
    Route::middleware('permission:attendance.view-team|attendance.manage')->group(function () {
        Route::get('team/today', [TeamController::class, 'today']);
        Route::get('daily', [RegisterController::class, 'daily']);
        Route::get('register', [RegisterController::class, 'register']);
        Route::get('report', [RegisterController::class, 'report']);
        Route::get('register/export', [RegisterController::class, 'export']);

        Route::get('overtime', [OvertimeController::class, 'index']);
        Route::post('overtime/{overtime}/approve', [OvertimeController::class, 'approve']);
        Route::post('overtime/{overtime}/reject', [OvertimeController::class, 'reject']);

        Route::get('timesheets/approvals', [TimesheetController::class, 'approvals']);
        Route::post('timesheets/{timesheet}/approve', [TimesheetController::class, 'approve']);
        Route::post('timesheets/{timesheet}/reject', [TimesheetController::class, 'reject']);
    });

    // HR administration
    Route::middleware('permission:attendance.manage')->group(function () {
        Route::post('punches', [RegisterController::class, 'storePunch']);
        Route::delete('punches/{punch}', [RegisterController::class, 'destroyPunch']);
        Route::put('days/{attendanceDay}/override', [RegisterController::class, 'override']);
        Route::put('employees/{employee}/days/{date}', [RegisterController::class, 'overrideDate']);
        Route::post('compute', [RegisterController::class, 'compute'])->middleware('throttle:10,1');

        Route::get('reports', [ReportController::class, 'index']);

        Route::get('shifts', [ShiftController::class, 'index']);
        Route::post('shifts', [ShiftController::class, 'store']);
        Route::put('shifts/{shift}', [ShiftController::class, 'update']);
        Route::delete('shifts/{shift}', [ShiftController::class, 'destroy']);
        Route::get('shifts/{shift}/employees', [ShiftController::class, 'employees']);
        Route::post('shifts/{shift}/assign', [ShiftController::class, 'assign']);
        Route::post('shifts/{shift}/unassign', [ShiftController::class, 'unassign']);

        Route::get('geofencing', [GeofenceController::class, 'index']);
        Route::post('geofencing', [GeofenceController::class, 'store']);
        Route::put('geofencing/{geofence}', [GeofenceController::class, 'update']);
        Route::delete('geofencing/{geofence}', [GeofenceController::class, 'destroy']);

        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices', [DeviceController::class, 'store']);
        Route::put('devices/{device}', [DeviceController::class, 'update']);
        Route::post('devices/{device}/rotate', [DeviceController::class, 'rotate']);
        Route::delete('devices/{device}', [DeviceController::class, 'destroy']);

        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
    });
});

