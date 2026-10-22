<?php

namespace App\Modules\Employees\Dashboard;

use App\Dashboard\Contracts\ApprovalProvider;
use App\Models\User;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\ProfileChangeRequest;
use App\Modules\Employees\Models\ResignationRequest;
use App\Modules\Employees\Services\LetterService;

/**
 * Dashboard inbox for HR (employees.update): sensitive profile changes and letter requests (one-click approve / reject),
 * and resignations (opened on the requests page, where HR processes the exit).
 */
class StaffRequestApprovals implements ApprovalProvider
{
    public function pendingFor(User $user, int $limit = 10): array
    {
        if (! $user->can('employees.update')) {
            return [];
        }
        $notMine = fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('user_id', '!=', $user->id)->orWhereNull('user_id'));
        $with = ['employee:id,first_name,last_name,user_id'];

        $changes = ProfileChangeRequest::where('status', 'pending')->where($notMine)->with($with)->oldest()->limit($limit)->get()
            ->map(fn (ProfileChangeRequest $r) => [
                'id' => $r->id, 'type' => 'profile_change',
                'title' => ($r->employee?->full_name ?? 'Employee').' — profile change',
                'subtitle' => $r->summary(), 'requested_at' => $r->created_at, 'url' => '/employees/requests?tab=profile',
                'approve_endpoint' => "/staff-requests/profile-changes/{$r->id}/approve",
                'reject_endpoint' => "/staff-requests/profile-changes/{$r->id}/reject",
            ]);

        $letters = LetterRequest::where('status', 'pending')->where($notMine)->with($with)->oldest()->limit($limit)->get()
            ->map(fn (LetterRequest $r) => [
                'id' => $r->id, 'type' => 'letter_request',
                'title' => ($r->employee?->full_name ?? 'Employee').' — '.(LetterService::TEMPLATES[$r->template][0] ?? 'letter'),
                'subtitle' => str($r->purpose)->limit(80)->toString(), 'requested_at' => $r->created_at, 'url' => '/employees/requests?tab=letters',
                'approve_endpoint' => "/staff-requests/letters/{$r->id}/approve",
                'reject_endpoint' => "/staff-requests/letters/{$r->id}/reject",
            ]);

        $resignations = ResignationRequest::where('status', 'pending')->where($notMine)->with($with)->oldest()->limit($limit)->get()
            ->map(fn (ResignationRequest $r) => [
                'id' => $r->id, 'type' => 'resignation',
                'title' => ($r->employee?->full_name ?? 'Employee').' — resignation',
                'subtitle' => 'Last working day '.$r->last_working_day?->format('d M Y'), 'requested_at' => $r->created_at,
                'url' => '/employees/requests?tab=resignations', 'approve_endpoint' => null, 'reject_endpoint' => null,
            ]);

        return $resignations->concat($changes)->concat($letters)->sortBy('requested_at')->take($limit)->values()->all();
    }

    public function countFor(User $user): int
    {
        if (! $user->can('employees.update')) {
            return 0;
        }
        $notMine = fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('user_id', '!=', $user->id)->orWhereNull('user_id'));

        return ProfileChangeRequest::where('status', 'pending')->where($notMine)->count()
            + LetterRequest::where('status', 'pending')->where($notMine)->count()
            + ResignationRequest::where('status', 'pending')->where($notMine)->count();
    }
}
