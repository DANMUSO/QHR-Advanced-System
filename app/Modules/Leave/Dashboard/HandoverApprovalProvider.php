<?php

namespace App\Modules\Leave\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;

/** Handover requests the user (as a colleague) still has to answer. */
class HandoverApprovalProvider implements ApprovalProvider
{
    public static function query(User $user): ?Builder
    {
        // Colleague, escalated answerer (colleague has no usable login), or leave admin when nobody reachable is assigned.
        return app(\App\Modules\Leave\Services\LeaveWorkflow::class)->handoverQueue($user);
    }

    public function pendingFor(User $user, int $limit = 10): array
    {
        $q = static::query($user);
        if (! $q) {
            return [];
        }

        return $q->with(['employee:id,first_name,last_name', 'leaveType:id,name'])->orderBy('created_at')->limit($limit)->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => 'handover-'.$r->id,
                'type' => 'handover',
                'title' => 'Handover: '.($r->employee?->full_name ?? 'Colleague'),
                'subtitle' => $r->leaveType?->name.' · '.$r->start_date->format('j M').' – '.$r->end_date->format('j M Y'),
                'requested_at' => ($r->submitted_at ?? $r->created_at)?->toIso8601String(),
                'url' => '/leave?tab=handovers',
                'approve_endpoint' => "/leave/requests/{$r->id}/handover/approve",
                'reject_endpoint' => "/leave/requests/{$r->id}/handover/reject",
            ])->all();
    }

    public function countFor(User $user): int
    {
        return static::query($user)?->count() ?? 0;
    }
}
