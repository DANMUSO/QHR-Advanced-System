<?php

namespace App\Modules\Leave\Payroll;

use App\Contracts\PayrollInputProvider;
use App\Models\Company;
use App\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveDayCounter;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/** Unpaid / half-pay leave days within a payroll period, and the current annual leave balance. */
class LeavePayrollInputs implements PayrollInputProvider
{
    public function __construct(private LeaveDayCounter $counter) {}

    public function inputsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $company = $employee->company ?? Company::find($employee->company_id);
        $unpaid = 0.0;
        $half = 0.0;

        $requests = LeaveRequest::withoutGlobalScopes()->where('employee_id', $employee->id)->taken()
            ->overlapping($periodStart->toDateString(), $periodEnd->toDateString())
            ->whereHas('leaveType', fn ($q) => $q->withoutGlobalScopes()->whereIn('paid', ['unpaid', 'half']))
            ->with(['leaveType' => fn ($q) => $q->withoutGlobalScopes()])->get();

        foreach ($requests as $r) {
            $from = Carbon::parse(max($r->start_date->toDateString(), $periodStart->toDateString()));
            $to = Carbon::parse(min($r->end_date->toDateString(), $periodEnd->toDateString()));
            $whole = $from->isSameDay($r->start_date) && $to->isSameDay($r->end_date);
            $days = ($whole || $r->hours) ? $r->days : $this->counter->forType($r->leaveType, $company, $from, $to)['days'];
            $r->leaveType->paid === 'unpaid' ? $unpaid += $days : $half += $days;
        }

        $annual = LeaveType::withoutGlobalScopes()->where('company_id', $employee->company_id)->where('code', 'ANNUAL')->first();
        $balance = $annual ? LeaveBalance::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('leave_type_id', $annual->id)->where('year', $periodEnd->year)->first() : null;

        return [
            'unpaid_leave_days' => round($unpaid, 2),
            'half_pay_leave_days' => round($half, 2),
            'annual_leave_balance' => $balance?->available ?? 0.0,
        ];
    }
}
