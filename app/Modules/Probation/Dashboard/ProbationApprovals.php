<?php

namespace App\Modules\Probation\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Organization\Support\ApprovalChain;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;

/**
 * Dashboard inbox: probation review stages waiting for this user's comment, and (probation.manage) reviews waiting for
 * the HR decision. Both need a written comment, so the items open the review page instead of one-click approve.
 */
class ProbationApprovals implements ApprovalProvider
{
    public function __construct(private ProbationRouting $routing) {}

    public function pendingFor(User $user, int $limit = 10): array
    {
        $stage = $this->routing->queueFor($user, $limit)->map(fn (ProbationReview $r) => [
            'id' => $r->id,
            'type' => 'probation',
            'title' => 'Probation review — '.($r->employee?->full_name ?? 'employee'),
            'subtitle' => ApprovalChain::label($this->routing->currentStep($r) ?? []).' · ends '.($r->probation_end_date?->format('d M Y') ?? '—'),
            'requested_at' => $r->updated_at,
            'url' => '/team/probation',
            'approve_endpoint' => null,
            'reject_endpoint' => null,
        ]);

        $hr = (ProbationRouting::hrQueue($user)?->with('employee')->orderBy('probation_end_date')->limit($limit)->get() ?? collect())
            ->reject(fn (ProbationReview $r) => $user->employee?->id === $r->employee_id)
            ->map(fn (ProbationReview $r) => [
                'id' => $r->id,
                'type' => 'probation',
                'title' => 'Probation decision — '.($r->employee?->full_name ?? 'employee'),
                'subtitle' => 'Confirm, extend or terminate · ends '.($r->probation_end_date?->format('d M Y') ?? '—'),
                'requested_at' => $r->updated_at,
                'url' => '/employees/'.$r->employee_id.'?tab=lifecycle',
                'approve_endpoint' => null,
                'reject_endpoint' => null,
            ]);

        return $stage->concat($hr)->take($limit)->values()->all();
    }

    public function countFor(User $user): int
    {
        return $this->routing->queueFor($user)->count() + (ProbationRouting::hrQueue($user)?->count() ?? 0);
    }
}
