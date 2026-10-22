<?php

namespace App\Modules\Leave\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Builder;

/**
 * Leave requests awaiting the user's supervisor decision, plus (for leave administrators) requests whose
 * applicant has no supervisor with an active account.
 */
class SupervisorApprovalProvider implements ApprovalProvider
{
    public static function query(User $user): ?Builder
    {
        $me = $user->employee;
        $isAdmin = $user->can('leave.manage');
        if (! $me && ! $isAdmin) {
            return null;
        }

        return LeaveRequest::where('status', LeaveRequest::PENDING_SUPERVISOR)
            ->when($me, fn ($q) => $q->where('employee_id', '!=', $me->id))
            ->where(function ($w) use ($me, $isAdmin, $user) {
                if ($me) {
                    // Built-in supervisor, or the resolved person of the current chain step (supervisor / secondary / HOD).
                    $w->where('supervisor_id', $me->id);
                }
                $roles = $user->getRoleNames()->all();
                if ($roles) {
                    // Current chain step is "anyone with role X".
                    $w->orWhereIn('approver_role', $roles);
                }
                if ($isAdmin) {
                    // No reachable approver (none, or no login / inactive / not activated): leave admins decide.
                    $w->orWhere(fn ($x) => $x->whereNull('approver_role')
                        ->where(fn ($y) => \App\Modules\Employees\Support\ApproverResolver::whereNoReachableApprover($y, 'supervisor_id')));
                }
            });
    }

    public function pendingFor(User $user, int $limit = 10): array
    {
        $q = static::query($user);
        if (! $q) {
            return [];
        }

        return $q->with(['employee:id,first_name,last_name,photo_path', 'leaveType:id,name'])->orderBy('updated_at')->limit($limit)->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => 'leave-'.$r->id,
                'type' => 'leave',
                'title' => ($r->employee?->full_name ?? 'Employee').' · '.$r->leaveType?->name,
                'subtitle' => $r->start_date->format('j M').($r->start_date->isSameDay($r->end_date) ? '' : ' – '.$r->end_date->format('j M Y')).' · '.rtrim(rtrim(number_format($r->days, 2), '0'), '.').' day(s)',
                'requested_at' => ($r->submitted_at ?? $r->created_at)?->toIso8601String(),
                'url' => '/leave/manage?tab=approvals',
                'approve_endpoint' => "/leave/requests/{$r->id}/approve",
                'reject_endpoint' => "/leave/requests/{$r->id}/reject",
            ])->all();
    }

    public function countFor(User $user): int
    {
        return static::query($user)?->count() ?? 0;
    }
}
