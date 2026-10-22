<?php

namespace App\Modules\Benefits\Payroll;

use App\Contracts\PayrollInputProvider;
use App\Models\Employee;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Notifications\ExpenseNotification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Notification;

/**
 * Expense claims Finance chose to pay "through payroll" (status payroll_pending) feed the next payroll run as
 * `reimbursements` (non-taxable, added to net pay). They are marked paid when that run is approved.
 */
class ExpenseReimbursementInputs implements PayrollInputProvider
{
    public function inputsFor(Employee $employee, CarbonInterface $periodStart, CarbonInterface $periodEnd): array
    {
        $amount = (float) ExpenseClaim::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->where('status', 'payroll_pending')->sum('amount');

        return $amount > 0 ? ['reimbursements' => round($amount, 2)] : [];
    }

    /**
     * PayrollRunApproved listener: settle the reimbursements included in the run. Only claims queued before the
     * run was last calculated were part of it; later ones wait for the next run.
     */
    public static function settle(object $event): void
    {
        $run = $event->run;
        $cutoff = $run->calculated_at ?? now();
        $lines = $run->lines()->withoutGlobalScopes()->get()
            ->filter(fn ($l) => (float) ($l->reimbursements ?? ((array) $l->inputs)['reimbursements'] ?? 0) > 0);
        if ($lines->isEmpty()) {
            return;
        }

        $claims = ExpenseClaim::withoutGlobalScope('company')->with('employee.user')
            ->whereIn('employee_id', $lines->pluck('employee_id'))
            ->where('status', 'payroll_pending')
            ->where('payroll_queued_at', '<=', $cutoff)->get();

        $period = $run->period ? $run->period->format('F Y') : 'payroll';
        foreach ($claims as $claim) {
            $claim->update([
                'status' => 'paid', 'paid_at' => now(), 'payroll_run_id' => $run->id,
                'payment_reference' => $claim->payment_reference ?: "Payroll {$period}",
            ]);
            if ($user = $claim->employee?->user) {
                Notification::send($user, new ExpenseNotification($claim, 'paid'));
            }
        }
        activity('expenses')->withProperties(['payroll_run_id' => $run->id, 'claims' => $claims->pluck('id')])
            ->log('Expense reimbursements paid through payroll');
    }
}
