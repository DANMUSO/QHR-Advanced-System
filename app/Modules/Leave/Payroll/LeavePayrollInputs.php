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

        // The standard ANNUAL type, else a company-defined equivalent (e.g. "AL"): the monthly-accruing type with its own balance.
        $types = LeaveType::withoutGlobalScopes()->where('company_id', $employee->company_id);
        $annual = (clone $types)->where('code', 'ANNUAL')->first()
            ?? (clone $types)->where('accrues_monthly', true)->where('has_balance', true)->whereNull('balance_type_id')->orderBy('id')->first();
        $balance = $annual ? LeaveBalance::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('leave_type_id', $annual->id)->where('year', $periodEnd->year)->first() : null;

        return array_filter([
            'unpaid_leave_days' => round($unpaid, 2),
            'half_pay_leave_days' => round($half, 2),
            'annual_leave_balance' => $balance?->available ?? 0.0,
            'annual_leave_settlement' => $this->settlementAtExit($employee, $annual, $company),
        ], fn ($v) => $v !== null);
    }

    /**
     * Annual leave owed (+) or over-taken (−) on the last working day, for final dues.
     *
     * The ledger accrues in advance on the 1st and stops "today", so a leaver serving notice looks short. Replace this
     * year's opening/accrual credits with the entitlement actually earned to the exit date — whole months at the
     * company rate, the exit month pro-rated by calendar day (e.g. exit 20 Nov → 20/30 of a month) — and keep
     * everything else (carry-forward, adjustments, leave taken). Rounded to 2 dp like the ledger.
     * Null when the employee has no (uncancelled) exit recorded.
     */
    private function settlementAtExit(Employee $employee, ?LeaveType $annual, Company $company): ?float
    {
        $exit = $employee->exitRecord()->where('status', '!=', 'cancelled')->first();
        if (! $annual || ! $exit?->exit_date) {
            return null;
        }
        $exitDate = Carbon::parse($exit->exit_date)->startOfDay();
        $joined = $employee->date_of_joining ? Carbon::parse($employee->date_of_joining)->startOfDay() : null;
        $rate = $annual->monthlyAccrual($company);

        $balance = LeaveBalance::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->where('leave_type_id', $annual->id)->where('year', $exitDate->year)->first();
        $posted = $balance ? (float) $balance->transactions()->whereIn('type', ['opening', 'accrual'])->sum('days') : 0.0;

        $month = ($joined && $joined->year === $exitDate->year) ? $joined->copy()->startOfMonth() : Carbon::create($exitDate->year, 1, 1);
        $earned = 0.0;
        for (; $month->lte($exitDate); $month->addMonth()) {
            if (! $month->isSameMonth($exitDate)) {
                $earned += $rate; // the joining month counts in full, as the opening credit does
                continue;
            }
            $firstDay = ($joined && $joined->isSameMonth($exitDate)) ? $joined->day : 1;
            $earned += $rate * max(0, $exitDate->day - $firstDay + 1) / $month->daysInMonth;
        }

        return round(($balance?->available ?? 0.0) - $posted + $earned, 2);
    }
}
