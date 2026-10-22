<?php

namespace App\Modules\Performance\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Organization\Support\ApprovalChain;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Services\GoalWorkflow;
use Illuminate\Support\Collection;

/**
 * KPI results awaiting approval and KPI review requests in the user's queue.
 * With a "kpi_review" approval chain configured the current chain step decides; otherwise the goal's approver
 * (the supervisor), plus performance admins for goals whose approver cannot act.
 */
class KpiApprovalProvider implements ApprovalProvider
{
    private const LIMIT = 300;

    /** @return Collection<int, Goal> */
    public static function goals(User $user): Collection
    {
        $me = $user->employee;
        $isAdmin = $user->can('performance.admin');
        if (! $me && ! $isAdmin) {
            return collect();
        }
        $companyId = (int) ($me?->company_id ?? $user->company_id);
        $workflow = app(GoalWorkflow::class);
        $base = Goal::whereIn('status', ['submitted', 'under_review'])
            ->when($me, fn ($q) => $q->where('employee_id', '!=', $me->id))
            ->with(['employee:id,first_name,last_name,photo_path,company_id,primary_supervisor_id,secondary_supervisor_id,department_id,user_id', 'kpi:id,name'])
            ->orderBy('updated_at');

        if ($companyId && ApprovalChain::steps($companyId, 'kpi_review')) {
            return $base->limit(self::LIMIT)->get()->filter(fn (Goal $g) => $workflow->awaits($g, $user))->values();
        }

        return $base->where(function ($w) use ($me, $isAdmin) {
            $w->where('approver_id', $me?->id ?? 0);
            if ($isAdmin) {
                // No reachable approver (none with a usable login up the line): performance admins decide.
                $w->orWhere(fn ($x) => ApproverResolver::whereApproverUnreachable($x, 'approver_id'));
            }
        })->limit(self::LIMIT)->get();
    }

    public function pendingFor(User $user, int $limit = 10): array
    {
        return static::goals($user)->take($limit)->map(function (Goal $g) {
            $review = $g->status === 'under_review';

            return [
                'id' => 'kpi-'.$g->id,
                'type' => $review ? 'kpi_review' : 'kpi',
                'title' => ($g->employee?->full_name ?? 'Employee').' · '.($g->kpi?->name ?? 'KPI'),
                'subtitle' => $review
                    ? 'Review requested: '.str($g->review_reason)->limit(80)
                    : 'Result '.rtrim(rtrim(number_format((float) $g->achieved, 2), '0'), '.').' of '.rtrim(rtrim(number_format((float) $g->target, 2), '0'), '.').' submitted',
                'requested_at' => ($review ? $g->updated_at : ($g->submitted_at ?? $g->updated_at))?->toIso8601String(),
                'url' => '/performance/manage?tab=approvals',
                'approve_endpoint' => $review ? null : "/performance/goals/{$g->id}/approve",
                'reject_endpoint' => $review ? "/performance/goals/{$g->id}/reject-review" : "/performance/goals/{$g->id}/reject",
            ];
        })->values()->all();
    }

    public function countFor(User $user): int
    {
        return static::goals($user)->count();
    }
}
