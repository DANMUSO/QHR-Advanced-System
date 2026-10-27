<?php

namespace App\Modules\Attendance\Payroll;

use App\Contracts\PayrollInputProvider;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Services\AttendanceService;
use Carbon\CarbonInterface;

/**
 * Feeds payroll with approved overtime hours (normal vs rest-day / holiday) and unexplained absences.
 * Overtime: every approved claim not yet paid, dated up to the period end, so claims approved after their own
 * month closed (late approvals) are paid in the next run. Claims already paid (status "paid", set by
 * MarkOvertimePaid when a run is approved) are never counted again.
 */
class AttendancePayrollInputs implements PayrollInputProvider
{
    public function inputsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $range = [$periodStart->toDateString(), $periodEnd->toDateString()];

        $ot = AttendanceOvertime::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->whereDate('date', '<=', $periodEnd->toDateString())
            ->where('status', 'approved')->whereNull('payroll_run_id')
            ->selectRaw("SUM(CASE WHEN day_type = 'working' THEN minutes ELSE 0 END) as normal, SUM(CASE WHEN day_type <> 'working' THEN minutes ELSE 0 END) as holiday")
            ->first();

        // Absences are rows written when a finished day is finalised; make sure that happened for this period.
        app(AttendanceService::class)->ensureFinalised($periodStart, $periodEnd, $employee->company_id);
        $absent = AttendanceDay::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->whereDate('date', '>=', $range[0])->whereDate('date', '<=', $range[1])->where('status', 'absent')->count();

        return [
            'overtime_hours_normal' => round(((int) ($ot->normal ?? 0)) / 60, 2),
            'overtime_hours_holiday' => round(((int) ($ot->holiday ?? 0)) / 60, 2),
            'days_absent' => (float) $absent,
        ];
    }
}
