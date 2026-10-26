<?php

namespace App\Modules\Payroll\Support;

use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayrollRunService;

class Presenter
{
    public static function run(PayrollRun $r, ?User $viewer = null): array
    {
        $r->loadMissing(['creator:id,name', 'calculator:id,name', 'reviewer:id,name', 'approver:id,name', 'payer:id,name', 'exitEmployee:id,first_name,last_name,employee_number']);

        return [
            'id' => $r->id,
            'company_id' => $r->company_id,
            'title' => $r->title,
            'period' => $r->period?->format('Y-m'),
            'period_label' => $r->period?->format('F Y'),
            'period_start' => $r->period_start?->toDateString(),
            'period_end' => $r->period_end?->toDateString(),
            'pay_date' => $r->pay_date?->toDateString(),
            'type' => $r->type,
            'off_cycle_reason' => $r->off_cycle_reason,
            'exit_employee' => $r->exitEmployee ? ['id' => $r->exitEmployee->id, 'name' => $r->exitEmployee->full_name, 'employee_number' => $r->exitEmployee->employee_number] : null,
            'options' => $r->options,
            'status' => $r->status,
            'needs_recalculation' => $r->needs_recalculation,
            'employee_count' => (int) $r->employee_count,
            'total_gross' => $r->total_gross,
            'total_deductions' => $r->total_deductions,
            'total_net' => $r->total_net,
            'total_paye' => $r->total_paye,
            'total_employer_cost' => $r->total_employer_cost,
            'exception_count' => (int) $r->exception_count,
            'notes' => $r->notes,
            'created_by' => $r->creator?->name,
            'calculated_by' => $r->calculator?->name,
            'calculated_at' => $r->calculated_at,
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at,
            'approved_by' => $r->approver?->name,
            'approved_at' => $r->approved_at,
            'paid_by' => $r->payer?->name,
            'paid_at' => $r->paid_at,
            'payslips_emailed_at' => $r->payslips_emailed_at,
            'created_at' => $r->created_at,
            'can' => $viewer ? [
                'edit' => $viewer->can('payroll.manage') && $r->isEditable(),
                'calculate' => $viewer->can('payroll.manage') && $r->isEditable(),
                // Segregation of duties: whoever calculated the run cannot review it.
                'review' => $r->status === 'calculated' && ! $r->needs_recalculation && app(PayrollRunService::class)->canReview($r, $viewer),
                'review_blocked_sod' => $r->status === 'calculated' && $viewer->can('payroll.manage') && (int) $r->calculated_by === (int) $viewer->id,
                'reopen' => $viewer->can('payroll.manage') && $r->status === 'reviewed',
                'approve' => $r->status === 'reviewed' && app(PayrollRunService::class)->canApprove($r, $viewer),
                'reject' => $r->status === 'reviewed' && $viewer->can('payroll.approve'),
                'mark_paid' => $r->status === 'approved' && app(PayrollRunService::class)->canMarkPaid($r, $viewer), // matches the route permission
                // Conflict of interest: the viewer is paid in this run, so they cannot review, approve or mark it paid.
                'own_pay_blocked' => in_array($r->status, ['calculated', 'reviewed', 'approved'], true)
                    && ($viewer->can('payroll.approve') || $viewer->can('payroll.review') || $viewer->can('payroll.manage'))
                    && app(PayrollRunService::class)->ownPayBlocked($r, $viewer),
                'email' => $r->isLocked() && $viewer->can('payroll.manage'),
                'delete' => $viewer->can('payroll.manage') && in_array($r->status, ['draft', 'calculated'], true),
                'export' => $r->status !== 'draft' && ($viewer->can('payroll.reports') || $viewer->can('payroll.manage') || $viewer->can('payroll.approve')),
            ] : null,
        ];
    }

    public static function line(PayrollRunEmployee $l, array $variance = []): array
    {
        $e = $l->employee;

        return [
            'id' => $l->id,
            'payroll_run_id' => $l->payroll_run_id,
            'employee' => $e ? [
                'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'photo_url' => $e->photo_url,
                'department' => $l->department?->name, 'branch' => $l->branch?->name,
            ] : null,
            'employee_name' => $e?->full_name,
            'department' => $l->department?->name,
            'basic_salary' => $l->basic_salary,
            'basic_pay' => $l->basic_pay,
            'days_worked' => $l->days_worked,
            'days_in_period' => $l->days_in_period,
            'unpaid_days' => $l->unpaid_days,
            'overtime_hours' => $l->overtime_hours,
            'overtime_pay' => $l->overtime_pay,
            'gross_pay' => $l->gross_pay,
            'taxable_pay' => $l->taxable_pay,
            'paye' => $l->paye,
            'nssf_employee' => $l->nssf_employee,
            'shif' => $l->shif,
            'ahl_employee' => $l->ahl_employee,
            'pension_employee' => $l->pension_employee,
            'other_deductions' => round($l->other_deductions + $l->helb, 2),
            'reimbursements' => (float) $l->reimbursements,
            'total_deductions' => $l->total_deductions,
            'net_pay' => $l->net_pay,
            'employer_cost' => $l->employer_cost,
            'payment_method' => $l->payment_method,
            'flags' => $l->flags ?? [],
            'emailed_at' => $l->emailed_at,
        ] + $variance;
    }
}
