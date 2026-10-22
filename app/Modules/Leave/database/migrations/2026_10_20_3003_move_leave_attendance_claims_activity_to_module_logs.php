<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Existing activity for Leave, Attendance and Claims records was logged as "default"; file it under the module's log. */
return new class extends Migration
{
    private const LOGS = [
        'leave' => ['App\Modules\Leave\Models\LeaveRequest', 'App\Modules\Leave\Models\LeaveType', 'App\Modules\Leave\Models\LeaveBalance'],
        'attendance' => ['App\Modules\Attendance\Models\AttendancePunch', 'App\Modules\Attendance\Models\AttendanceDay', 'App\Modules\Attendance\Models\AttendanceDevice',
            'App\Modules\Attendance\Models\AttendanceShift', 'App\Modules\Attendance\Models\AttendanceGeofence', 'App\Modules\Attendance\Models\AttendanceOvertime', 'App\Modules\Attendance\Models\AttendanceTimesheet'],
        'claims' => ['App\Modules\Claims\Models\MedicalClaim', 'App\Modules\Claims\Models\ClaimLimit', 'App\Modules\Claims\Models\ClaimApprover'],
    ];

    public function up(): void
    {
        $table = config('activitylog.table_name', 'activity_log');
        if (! Schema::hasTable($table)) {
            return;
        }
        foreach (self::LOGS as $log => $types) {
            DB::table($table)->where(fn ($q) => $q->whereNull('log_name')->orWhere('log_name', 'default'))
                ->whereIn('subject_type', $types)->update(['log_name' => $log]);
        }
    }

    public function down(): void
    {
        // Not reversible: the original "default" name carried no information.
    }
};
