<?php

namespace App\Modules\Attendance\Services;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Support\Presenter;
use Carbon\CarbonImmutable;

/** Per-employee calendars (month) and week timesheets, combining stored days with live evaluation of today. */
class HistoryBuilder
{
    public function __construct(private AttendanceService $service, private LeaveLookup $leave) {}

    /** Display labels for day statuses (stored and live). */
    public const STATUS_LABELS = [
        'present' => 'Present', 'late' => 'Late', 'absent' => 'Absent', 'on_leave' => 'On leave', 'incomplete' => 'No clock-out',
        'in_progress' => 'In progress', 'not_in' => 'Not clocked in yet', 'upcoming' => 'Upcoming',
        'weekend' => 'Off', 'holiday' => 'Holiday', 'day_off' => 'Off', 'not_joined' => 'Before joining',
    ];

    public static function statusLabel(?string $status): ?string
    {
        return $status ? (self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status))) : null;
    }

    /**
     * One entry per calendar date in [from, to]: stored row if any, today evaluated live,
     * otherwise the expected status: weekend / holiday, on_leave, absent (a past working day nobody computed yet),
     * not_in (today, no punch yet) or upcoming. Today without a clock-out is in_progress.
     */
    public function range(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, bool $withPunches = true): array
    {
        $days = AttendanceDay::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get()
            ->keyBy(fn ($d) => $d->date->toDateString());
        $punches = $withPunches
            ? AttendancePunch::withoutGlobalScope('company')->with(['device:id,name', 'creator:id,name'])->where('employee_id', $employee->id)
                ->whereBetween('punched_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('punched_at')->get()
                ->groupBy(fn ($p) => $p->punched_at->toDateString())
            : collect();
        $rules = $this->service->rulesFor($employee);
        $today = CarbonImmutable::today();
        $leaveDays = $this->leave->leaveDays($from, $to, [$employee->id]);
        $joined = $employee->date_of_joining ? CarbonImmutable::parse($employee->date_of_joining)->startOfDay() : null;

        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $key = $d->toDateString();
            $type = $this->service->dayType($employee, $rules, $d);
            if ($row = $days->get($key)) {
                $entry = Presenter::day($row);
            } elseif ($d->isSameDay($today) && ($punches[$key] ?? collect())->isNotEmpty()) {
                $r = $this->service->evaluate($employee, $d, $punches[$key]);
                $entry = [
                    'id' => null, 'date' => $key, 'employee_id' => $employee->id, 'day_type' => $r['day_type'],
                    'first_in' => Presenter::time($r['first_in']), 'last_out' => Presenter::time($r['last_out']),
                    'punch_count' => $r['punch_count'], 'worked_minutes' => $r['worked_minutes'], 'worked_formatted' => Presenter::duration($r['worked_minutes']),
                    'regular_minutes' => $r['regular_minutes'], 'regular_formatted' => Presenter::duration($r['regular_minutes']),
                    'late_minutes' => $r['late_minutes'], 'early_leave_minutes' => $r['early_leave_minutes'],
                    'overtime_minutes' => $r['overtime_minutes'], 'overtime_formatted' => Presenter::duration($r['overtime_minutes']),
                    'status' => $r['status'], 'computed_status' => $r['status'], 'override_status' => null, 'override_reason' => null,
                ];
            } else {
                $entry = [
                    'id' => null, 'date' => $key, 'employee_id' => $employee->id, 'day_type' => $type,
                    'first_in' => null, 'last_out' => null, 'punch_count' => 0, 'worked_minutes' => 0, 'regular_minutes' => 0,
                    'late_minutes' => 0, 'early_leave_minutes' => 0, 'overtime_minutes' => 0,
                    'status' => match (true) {
                        $type !== 'working' => $type,
                        $joined && $d->lt($joined) => null,
                        isset($leaveDays[$key][$employee->id]) => 'on_leave',
                        $d->isSameDay($today) => 'not_in',
                        $d->gt($today) => 'upcoming',
                        default => 'absent', // past working day, no punches, no leave
                    },
                ];
            }
            // Clocked in today but not out yet: the day is still running.
            if ($d->isSameDay($today) && ($entry['punch_count'] ?? 0) > 0 && ! $entry['last_out'] && empty($entry['override_status'])
                && in_array($entry['status'], ['present', 'late', 'incomplete'], true)) {
                $entry['status'] = 'in_progress';
            }
            $entry['status_label'] = static::statusLabel($entry['status']);
            $entry['weekday'] = $d->format('D');
            $entry['is_today'] = $d->isSameDay($today);
            if ($withPunches) {
                $entry['punches'] = ($punches[$key] ?? collect())->map(fn ($p) => Presenter::punch($p))->values()->all();
            }
            $out[] = $entry;
        }

        return $out;
    }

    public static function totals(array $days): array
    {
        $c = collect($days);

        return [
            'days_present' => $c->whereIn('status', ['present', 'late', 'incomplete', 'in_progress'])->count(),
            'late_count' => $c->where('status', 'late')->count(),
            'late_minutes' => (int) $c->sum('late_minutes'),
            'absent_count' => $c->where('status', 'absent')->count(),
            'on_leave_count' => $c->where('status', 'on_leave')->count(),
            'incomplete_count' => $c->where('status', 'incomplete')->count(),
            'worked_minutes' => (int) $c->sum('worked_minutes'),
            'regular_minutes' => (int) $c->sum('regular_minutes'),
            'overtime_minutes' => (int) $c->sum('overtime_minutes'),
            'worked_hours' => round($c->sum('worked_minutes') / 60, 1),
            'overtime_hours' => round($c->sum('overtime_minutes') / 60, 1),
        ];
    }
}
