<?php

namespace App\Contracts;

use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Lets modules feed figures into a payroll run without Payroll depending on them directly.
 * Register implementations in a module manifest under 'payroll_inputs'.
 * Values returned by all providers are summed key by key.
 *
 * Recognised keys:
 *   unpaid_leave_days        float  working days of unpaid leave in the period (deducted pro-rata from basic)
 *   half_pay_leave_days      float  working days paid at 50% (e.g. sick leave, half pay)
 *   overtime_hours_normal    float  weekday overtime hours (paid at the company's normal OT multiplier)
 *   overtime_hours_holiday   float  rest-day / public-holiday overtime hours (holiday multiplier)
 *   days_absent              float  unexplained absences (informational; deduction is a company policy choice)
 *   annual_leave_balance     float  current annual leave balance in days
 *   annual_leave_settlement  float  leavers only: annual leave accrued to the exit date (exit month pro-rated) net of
 *                                   leave taken; final dues encash it when positive and recover it when negative
 *   reimbursements           float  KES owed to the employee for approved expense claims paid through payroll.
 *                                   Non-taxable and not pay: excluded from gross, PAYE, NSSF, SHIF and AHL, added
 *                                   to net pay and shown on the payslip as "Expense reimbursements". Collected for
 *                                   regular and final-dues runs; the provider should report only items not yet paid
 *                                   (listen to App\Modules\Payroll\Events\PayrollRunApproved to mark them paid).
 */
interface PayrollInputProvider
{
    public function inputsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array;
}
