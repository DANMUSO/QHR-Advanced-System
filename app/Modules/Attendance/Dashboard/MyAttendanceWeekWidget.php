<?php

namespace App\Modules\Attendance\Dashboard;

use App\Dashboard\Widget;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceDay;

/** The signed-in employee's clock-in/out for the current week. */
class MyAttendanceWeekWidget extends Widget
{
    public function key(): string   { return 'my_attendance_week'; }
    public function title(): string { return 'My attendance this week'; }
    public function size(): string  { return 'sm'; }

    public function visible(User $user): bool
    {
        return $user->employee !== null && $user->can('attendance.view-own');
    }

    public function data(User $user): array
    {
        $weekStart = now()->startOfWeek();
        $weekEnd   = now()->endOfWeek();

        $days = AttendanceDay::withoutGlobalScopes()
            ->where('employee_id', $user->employee->id)
            ->whereBetween('date', [$weekStart->toDateString(), $weekEnd->toDateString()])
            ->orderBy('date')
            ->get();

        $totalMinutes = $days->sum(fn ($d) => (int) $d->worked_minutes);
        $otMinutes    = $days->sum(fn ($d) => (int) $d->overtime_minutes);

        return [
            'week_start'     => $weekStart->toDateString(),
            'week_end'       => $weekEnd->toDateString(),
            'total_worked'   => $totalMinutes > 0 ? sprintf('%dh %02dm', intdiv($totalMinutes, 60), $totalMinutes % 60) : '—',
            'total_overtime' => $otMinutes > 0 ? sprintf('%dh %02dm', intdiv($otMinutes, 60), $otMinutes % 60) : '—',
            'days'           => $days->map(fn ($d) => [
                'date'             => $d->date->toDateString(),
                'day_name'         => $d->date->format('D'),
                'first_in'         => $d->first_in,
                'last_out'         => $d->last_out,
                'worked_formatted' => $d->worked_formatted,
                'status'           => $d->status,
            ])->values(),
        ];
    }
}
