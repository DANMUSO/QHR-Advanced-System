<?php

namespace App\Modules\Attendance\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceAccess;
use Carbon\CarbonImmutable;

class TeamAttendanceTodayWidget extends Widget
{
    public function key(): string
    {
        return 'team_attendance_today';
    }

    public function title(): string
    {
        return 'Team attendance today';
    }

    public function size(): string
    {
        return 'md';
    }

    public function visible(User $user): bool
    {
        if (AttendanceAccess::managesAll($user)) {
            return true;
        }

        return $user->can('attendance.view-team') && AttendanceAccess::teamQuery($user)->exists();
    }

    public function data(User $user): array
    {
        $employees = AttendanceAccess::teamQuery($user)->orderBy('first_name')
            ->get(['id', 'company_id', 'first_name', 'last_name', 'photo_path', 'branch_id']);
        $statuses = app(AttendanceService::class)->liveStatuses($employees, CarbonImmutable::today());

        $groups = [];
        foreach (['present', 'late', 'absent', 'on_leave', 'not_in', 'day_off'] as $g) {
            $members = $employees->filter(fn ($e) => ($statuses[$e->id]['status'] ?? null) === $g);
            $groups[$g] = [
                'count' => $members->count(),
                'people' => $members->take(8)->map(fn ($e) => [
                    'id' => $e->id, 'name' => $e->full_name, 'photo_url' => $e->photo_url,
                    'first_in' => ($t = $statuses[$e->id]['first_in'] ?? null) ? $t->format('H:i') : null,
                    'late_minutes' => $statuses[$e->id]['late_minutes'] ?? 0,
                ])->values(),
            ];
        }
        $total = $employees->count();
        $in = $groups['present']['count'] + $groups['late']['count'];

        return [
            'scope' => AttendanceAccess::managesAll($user) ? 'company' : 'team',
            'total' => $total,
            'in_count' => $in,
            'attendance_rate' => $total ? round($in / max(1, $total - $groups['on_leave']['count'] - $groups['day_off']['count']) * 100) : 0,
            'groups' => $groups,
        ];
    }
}
