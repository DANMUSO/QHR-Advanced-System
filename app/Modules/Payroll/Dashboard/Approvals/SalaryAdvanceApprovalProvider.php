<?php

namespace App\Modules\Payroll\Dashboard\Approvals;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use Illuminate\Database\Eloquent\Builder;

/** Pending salary advance requests for payroll officers and approvers (never their own request). */
class SalaryAdvanceApprovalProvider implements ApprovalProvider
{
    private function query(User $user): ?Builder
    {
        if (! $user->can('payroll.manage') && ! $user->can('payroll.approve')) {
            return null;
        }

        return PayrollSalaryAdvance::where('status', 'pending')
            ->when($user->employee?->id, fn ($q, $id) => $q->where('employee_id', '!=', $id));
    }

    public function pendingFor(User $user, int $limit = 10): array
    {
        $query = $this->query($user);
        if (! $query) {
            return [];
        }

        return $query->with('employee:id,first_name,last_name,employee_number')->oldest()->limit($limit)->get()
            ->map(fn (PayrollSalaryAdvance $a) => [
                'id' => $a->id,
                'type' => 'salary_advance',
                'title' => ($a->employee?->full_name ?? 'Employee').' — salary advance KES '.number_format((float) $a->amount),
                'subtitle' => 'Repay over '.max(1, (int) $a->repayment_months).' month(s)'.($a->reason ? ' · '.str($a->reason)->limit(60) : ''),
                'requested_at' => $a->created_at,
                'url' => '/payroll?tab=advances',
                'approve_endpoint' => "/payroll/advances/{$a->id}/approve",
                'reject_endpoint' => "/payroll/advances/{$a->id}/reject",
            ])->all();
    }

    public function countFor(User $user): int
    {
        return $this->query($user)?->count() ?? 0;
    }
}
