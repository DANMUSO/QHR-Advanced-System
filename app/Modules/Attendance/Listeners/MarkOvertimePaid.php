<?php

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Models\AttendanceOvertime;
use Illuminate\Support\Facades\DB;

/**
 * PayrollRunApproved: the approved overtime that the run paid (approved, not yet paid, dated up to the run's
 * period end, for the run's employees) becomes "paid" with the run id. Mirrors AttendancePayrollInputs, which
 * feeds exactly that overtime into a regular run. Off-cycle runs carry no overtime, so they are ignored.
 */
class MarkOvertimePaid
{
    public function handle(object $event): void
    {
        $run = $event->run;
        if (($run->type ?? 'regular') !== 'regular' || ! $run->period_end) {
            return;
        }
        $employeeIds = DB::table('payroll_run_employees')->where('payroll_run_id', $run->id)->pluck('employee_id');
        if ($employeeIds->isEmpty()) {
            return;
        }

        $count = AttendanceOvertime::withoutGlobalScope('company')
            ->where('company_id', $run->company_id)
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')->whereNull('payroll_run_id')
            ->whereDate('date', '<=', $run->period_end->toDateString())
            ->update(['status' => 'paid', 'payroll_run_id' => $run->id, 'paid_at' => now(), 'updated_at' => now()]);

        if ($count) {
            activity('attendance')->withProperties(['payroll_run_id' => $run->id, 'claims' => $count])->log('Overtime marked paid');
        }
    }
}
