<?php

namespace App\Modules\Recruitment\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Recruitment\Models\Requisition;
use Illuminate\Database\Eloquent\Builder;

/** Dashboard inbox: staff requisitions awaiting approval by someone other than the requester. */
class RequisitionApprovals implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        return ($this->query($user)?->with(['position:id,title', 'department:id,name', 'requester:id,name'])
            ->oldest()->limit($limit)->get() ?? collect())
            ->map(fn (Requisition $r) => [
                'id' => $r->id,
                'type' => 'requisition',
                'title' => "Requisition {$r->requisition_number}: {$r->vacancies} × ".($r->position?->title ?? 'position'),
                'subtitle' => trim(($r->department?->name ?? '').' · requested by '.($r->requester?->name ?? 'unknown'), ' ·'),
                'requested_at' => $r->created_at,
                'url' => '/recruitment?tab=requisitions',
                'approve_endpoint' => "/recruitment/requisitions/{$r->id}/approve",
                'reject_endpoint' => "/recruitment/requisitions/{$r->id}/reject",
            ])->values()->all();
    }

    public function countFor(User $user): int
    {
        return $this->query($user)?->count() ?? 0;
    }

    private function query(User $user): ?Builder
    {
        if (! $user->can('recruitment.manage')) {
            return null;
        }

        return Requisition::where('status', 'pending_approval')
            ->when(! $user->hasRole('super-admin'), fn ($q) => $q->where(fn ($w) => $w->whereNull('requested_by')->orWhere('requested_by', '!=', $user->id)));
    }
}
