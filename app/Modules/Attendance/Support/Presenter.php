<?php

namespace App\Modules\Attendance\Support;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePunch;

/** JSON shapes shared by controllers, widgets and exports. */
class Presenter
{
    public static function duration(?int $minutes): ?string
    {
        return $minutes > 0 ? sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60) : null;
    }

    public static function time($dt): ?string
    {
        return $dt ? $dt->format('H:i') : null;
    }

    public static function employee(?Employee $e): ?array
    {
        return $e ? [
            'id' => $e->id,
            'name' => $e->full_name,
            'employee_number' => $e->employee_number,
            'photo_url' => $e->photo_url,
            'branch' => $e->relationLoaded('branch') ? $e->branch?->name : null,
            'department' => $e->relationLoaded('department') ? $e->department?->name : null,
        ] : null;
    }

    public static function day(AttendanceDay $d): array
    {
        return [
            'id' => $d->id,
            'date' => $d->date?->toDateString(),
            'employee_id' => $d->employee_id,
            'employee' => $d->relationLoaded('employee') ? static::employee($d->employee) : null,
            'day_type' => $d->day_type,
            'first_in' => static::time($d->first_in),
            'last_out' => static::time($d->last_out),
            'punch_count' => $d->punch_count,
            'worked_minutes' => $d->worked_minutes,
            'worked_formatted' => static::duration($d->worked_minutes),
            'regular_minutes' => $d->regular_minutes,
            'regular_formatted' => static::duration($d->regular_minutes),
            'late_minutes' => $d->late_minutes,
            'early_leave_minutes' => $d->early_leave_minutes,
            'overtime_minutes' => $d->overtime_minutes,
            'overtime_formatted' => static::duration($d->overtime_minutes),
            'status' => $d->status,
            'status_label' => \App\Modules\Attendance\Services\HistoryBuilder::statusLabel($d->status),
            'computed_status' => $d->computed_status,
            'override_status' => $d->override_status,
            'override_reason' => $d->override_reason,
            'overridden_at' => $d->overridden_at,
        ];
    }

    public static function punch(AttendancePunch $p): array
    {
        return [
            'id' => $p->id,
            'punched_at' => $p->punched_at?->toIso8601String(),
            'time' => $p->punched_at?->format('H:i:s'),
            'direction' => $p->direction,
            'source' => $p->source,
            'device' => $p->relationLoaded('device') ? $p->device?->name : null,
            'latitude' => $p->latitude,
            'longitude' => $p->longitude,
            'accuracy_m' => $p->accuracy_m,
            'distance_m' => $p->distance_m,
            'note' => $p->note,
            'created_by' => $p->relationLoaded('creator') ? $p->creator?->name : null,
        ];
    }

    /** Notify an employee's user account (skipped when they have none). Failures never break the request. */
    public static function notify(?\App\Models\Employee $employee, \Illuminate\Notifications\Notification $notification): void
    {
        $user = $employee?->user;
        if (! $user) {
            return;
        }
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
