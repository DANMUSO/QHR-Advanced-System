<?php

namespace App\Modules\Attendance\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Reads approved leave from the Leave module, if it is installed, without depending on its classes.
 * Everything Leave-specific lives here.
 */
class LeaveLookup
{
    /** Leave statuses that mean "the employee is away". */
    public const APPROVED = ['approved', 'amended_approved', 'taken', 'recalled']; // recalled: end_date is cut to the recall date

    private static ?bool $available = null;

    public static function available(): bool
    {
        if (static::$available === null) {
            try {
                static::$available = Schema::hasTable('leave_requests')
                    && Schema::hasColumns('leave_requests', ['employee_id', 'start_date', 'end_date', 'status']);
            } catch (Throwable) {
                static::$available = false;
            }
        }

        return static::$available;
    }

    /**
     * Employee ids (of $employeeIds, or all when null) on approved leave on $date.
     *
     * @return array<int, int>
     */
    public function onLeave(CarbonInterface $date, ?array $employeeIds = null): array
    {
        return array_keys($this->leaveDays($date, $date, $employeeIds)[$date->toDateString()] ?? []);
    }

    /**
     * Map of date => [employee_id => true] for approved leave overlapping the range.
     * Weekends are included; callers decide whether a date is a working day.
     */
    public function leaveDays(CarbonInterface $from, CarbonInterface $to, ?array $employeeIds = null): array
    {
        if (! static::available() || ($employeeIds !== null && ! $employeeIds)) {
            return [];
        }
        try {
            $rows = DB::table('leave_requests')
                ->whereIn('status', self::APPROVED)
                // recalled before it started: no leave was taken
                ->when(Schema::hasColumn('leave_requests', 'days'), fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'recalled')->orWhere('days', '>', 0)))
                ->whereDate('start_date', '<=', $to->toDateString())
                ->whereDate('end_date', '>=', $from->toDateString())
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->when(Schema::hasColumn('leave_requests', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                // Hourly leave is a window inside a worked day, not a day away (see partialLeave()).
                ->when(Schema::hasColumn('leave_requests', 'start_time'), fn ($q) => $q->whereNull('start_time'))
                ->get(['employee_id', 'start_date', 'end_date']);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        $fromStr = $from->toDateString();
        $toStr = $to->toDateString();
        foreach ($rows as $r) {
            $d = max(substr($r->start_date, 0, 10), $fromStr);
            $end = min(substr($r->end_date, 0, 10), $toStr);
            while ($d <= $end) {
                $map[$d][(int) $r->employee_id] = true;
                $d = date('Y-m-d', strtotime($d.' +1 day'));
            }
        }

        return $map;
    }

    /**
     * Approved hourly leave on $date: employee_id => ['from' => 'HH:MM', 'to' => 'HH:MM'] (the excused window).
     *
     * @return array<int, array{from: string, to: string}>
     */
    public function partialLeave(CarbonInterface $date, ?array $employeeIds = null): array
    {
        if (! static::available() || ($employeeIds !== null && ! $employeeIds) || ! Schema::hasColumns('leave_requests', ['start_time', 'end_time'])) {
            return [];
        }
        try {
            $rows = DB::table('leave_requests')
                ->whereIn('status', ['approved', 'amended_approved', 'taken'])
                ->whereNotNull('start_time')->whereNotNull('end_time')
                ->whereDate('start_date', '<=', $date->toDateString())
                ->whereDate('end_date', '>=', $date->toDateString())
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->get(['employee_id', 'start_time', 'end_time']);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $from = substr($r->start_time, 0, 5);
            $to = substr($r->end_time, 0, 5);
            $id = (int) $r->employee_id;
            // Two hourly requests on one day: excuse the span covering both.
            $out[$id] = isset($out[$id]) ? ['from' => min($out[$id]['from'], $from), 'to' => max($out[$id]['to'], $to)] : ['from' => $from, 'to' => $to];
        }

        return $out;
    }
}
