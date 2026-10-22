<?php

namespace App\Modules\Payroll\Dashboard\Approvals;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Services\PayrollRunService;

/**
 * Payroll runs awaiting this user's review or approval decision.
 */
class PayrollRunApprovalProvider implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        return $this->query($user)->limit($limit)->get()
            ->map(function (PayrollRun $run) use ($user) {
                $service = app(PayrollRunService::class);
                $canApprove = $run->status === 'reviewed' && $service->canApprove($run, $user);
                $canReview  = $run->status === 'calculated' && $service->canReview($run, $user);

                return [
                    'id'             => 'payroll-'.$run->id,
                    'type'           => 'payroll',
                    'title'          => $run->title,
                    'subtitle'       => $canApprove ? 'Awaiting your approval' : 'Awaiting review',
                    'requested_at'   => ($run->calculated_at ?? $run->created_at)?->toIso8601String(),
                    'url'            => '/payroll/runs/'.$run->id,
                    'approve_endpoint' => $canApprove ? "/payroll/runs/{$run->id}/approve" : null,
                    'reject_endpoint'  => $canApprove ? "/payroll/runs/{$run->id}/reject"  : null,
                ];
            })->all();
    }

    public function countFor(User $user): int
    {
        return $this->query($user)->count();
    }

    private function query(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $service = app(PayrollRunService::class);

        return PayrollRun::whereIn('status', ['calculated', 'reviewed'])
            // Nobody reviews or approves a run that pays their own salary.
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('payroll_run_employees')
                ->whereColumn('payroll_run_employees.payroll_run_id', 'payroll_runs.id')
                ->whereIn('payroll_run_employees.employee_id', fn ($e) => $e->select('id')->from('employees')->where('user_id', $user->id)))
            ->where(function ($q) use ($user, $service) {
                // Runs the user can review (calculated, not the calculator)
                if ($user->can('payroll.manage')) {
                    $q->orWhere(fn ($w) => $w->where('status', 'calculated')->where('needs_recalculation', false)
                        ->where(fn ($x) => $x->whereNull('calculated_by')->orWhere('calculated_by', '!=', $user->id)));
                }
                // Runs the user can approve (reviewed, not preparer/calculator/reviewer)
                if ($user->can('payroll.approve')) {
                    $q->orWhere(fn ($w) => $w->where('status', 'reviewed')
                        ->where(fn ($x) => $x->whereNull('created_by')->orWhere('created_by', '!=', $user->id))
                        ->where(fn ($x) => $x->whereNull('calculated_by')->orWhere('calculated_by', '!=', $user->id))
                        ->where(fn ($x) => $x->whereNull('reviewed_by')->orWhere('reviewed_by', '!=', $user->id)));
                }
            });
    }
}
