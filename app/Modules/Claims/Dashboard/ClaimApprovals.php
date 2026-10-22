<?php

namespace App\Modules\Claims\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Claims\Http\Controllers\ApprovalController;
use App\Modules\Claims\Models\MedicalClaim;
use Illuminate\Database\Eloquent\Builder;

/**
 * Feeds the dashboard approvals inbox: claims awaiting this user's HR decision (one-click approve/reject),
 * and — for Finance — HR-approved claims awaiting payment (opened on the Payments page, since paying needs a method/reference).
 */
class ClaimApprovals implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        $hr = ApprovalController::queueFor($user)->with('employee:id,first_name,last_name')
            ->orderBy('submitted_at')->limit($limit)->get()
            ->map(fn (MedicalClaim $c) => [
                'id' => $c->id,
                'type' => 'claim',
                'title' => ($c->employee?->full_name ?? 'Employee').' — medical claim KES '.number_format((float) $c->total_amount),
                'subtitle' => trim(($c->reference ?? '').' · '.($c->provider_name ?? ucfirst((string) $c->claimant_type)), ' ·'),
                'requested_at' => $c->submitted_at ?? $c->created_at,
                'url' => '/claims/approvals',
                'approve_endpoint' => "/claims/{$c->id}/approve",
                'reject_endpoint' => "/claims/{$c->id}/reject",
            ]);

        $finance = $this->financeQuery($user)?->with('employee:id,first_name,last_name')
            ->orderBy('hr_decided_at')->limit($limit)->get()
            ->map(fn (MedicalClaim $c) => [
                'id' => $c->id,
                'type' => 'claim',
                'title' => 'Pay '.($c->employee?->full_name ?? 'employee').' — KES '.number_format((float) ($c->approved_amount ?? $c->total_amount)),
                'subtitle' => ($c->reference ?? '').' · approved by HR',
                'requested_at' => $c->hr_decided_at ?? $c->updated_at,
                'url' => '/claims/payments',
                'approve_endpoint' => null,
                'reject_endpoint' => null,
            ]) ?? collect();

        return $hr->concat($finance)->take($limit)->values()->all();
    }

    public function countFor(User $user): int
    {
        return ApprovalController::queueFor($user)->count() + ($this->financeQuery($user)?->count() ?? 0);
    }

    private function financeQuery(User $user): ?Builder
    {
        return $user->can('claims.pay') ? MedicalClaim::where('status', MedicalClaim::HR_APPROVED) : null;
    }
}
