<?php

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Support\AttendanceRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure attendance rules for one employee-day (no database access; unit-tested directly).
 *
 *  first in  = earliest punch that is not an explicit "out"
 *  last out  = latest punch after first in that is not an explicit "in"
 *  late      = minutes after the expected start, counted only when beyond the grace period
 *  early     = minutes before the expected end
 *  worked    = last out − first in (total actual time)
 *  regular   = min(last out, end) − first in − break
 *  overtime  = last out − max(end, first in) on working days; all worked time on weekends and holidays
 *  status    = present | late | absent | on_leave | weekend | holiday | incomplete (no out-punch on a finished day, "NA")
 */
final class DayCalculator
{
    /**
     * @param  array<int, array{at: CarbonInterface, direction?: string}>  $punches
     * @param  string  $dayType  working | weekend | holiday
     * @param  bool  $final  the day is over, so a missing out-punch makes it "incomplete"
     * @param  array{from: string, to: string}|null  $leaveWindow  approved hourly leave: the day is worked, only this
     *                                                               window is excused (late arrival / early leave inside it)
     */
    public function calculate(CarbonInterface $date, array $punches, AttendanceRules $rules, string $dayType = 'working', bool $onLeave = false, bool $final = true, ?array $leaveWindow = null): array
    {
        $windowFrom = $windowTo = null;
        if ($leaveWindow && ! $onLeave && $dayType === 'working') {
            $windowFrom = CarbonImmutable::parse($date->toDateString().' '.$leaveWindow['from'], $date->getTimezone());
            $windowTo = CarbonImmutable::parse($date->toDateString().' '.$leaveWindow['to'], $date->getTimezone());
            // Hourly leave covering the whole shift is a day away after all.
            if ($windowFrom->lte($rules->startAt($date)) && $windowTo->gte($rules->endAt($date))) {
                $onLeave = true;
                $windowFrom = $windowTo = null;
            }
        }
        $punches = array_map(fn ($p) => ['at' => CarbonImmutable::instance($p['at']), 'direction' => $p['direction'] ?? 'auto'], $punches);
        usort($punches, fn ($a, $b) => $a['at'] <=> $b['at']);

        $first = null;
        foreach ($punches as $p) {
            if ($p['direction'] !== 'out') {
                $first = $p['at'];
                break;
            }
        }
        $last = null;
        if ($first) {
            foreach (array_reverse($punches) as $p) {
                if ($p['direction'] !== 'in' && $p['at']->greaterThan($first)) {
                    $last = $p['at'];
                    break;
                }
            }
        }

        $result = [
            'day_type' => $dayType,
            'first_in' => $first,
            'last_out' => $last,
            'punch_count' => count($punches),
            'worked_minutes' => 0,
            'regular_minutes' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'overtime_minutes' => 0,
            'status' => 'absent',
        ];

        if (! $punches) {
            $result['status'] = match (true) {
                $dayType !== 'working' => $dayType,
                $onLeave => 'on_leave',
                default => 'absent',
            };

            return $result;
        }

        if ($first && $last) {
            $result['worked_minutes'] = self::minutes($first, $last);
        }

        if ($dayType !== 'working') {
            $result['overtime_minutes'] = $result['worked_minutes'];
            $result['status'] = ($final && ! $last) ? 'incomplete' : $dayType;

            return $result;
        }

        $start = $rules->startAt($date);
        $end = $rules->endAt($date);
        if ($windowFrom) {
            // Leave at the start of the day moves the expected arrival; leave at the end moves the expected departure.
            if ($windowFrom->lte($start->addMinutes($rules->graceMinutes)) && $windowTo->gt($start)) {
                $start = $windowTo->min($end);
            }
            if ($windowTo->gte($end) && $windowFrom->lt($end)) {
                $end = $windowFrom->max($start);
            }
        }

        if ($first) {
            $lateBy = self::minutes($start, $first);
            $result['late_minutes'] = $lateBy > $rules->graceMinutes ? $lateBy : 0;
        }
        if ($first && $last) {
            $result['early_leave_minutes'] = self::minutes($last, $end);
            $result['regular_minutes'] = max(0, self::minutes($first, $last->min($end)) - $rules->breakMinutes);
            $result['overtime_minutes'] = self::minutes($first->max($rules->endAt($date)), $last); // the shift end, even with hourly leave
        }
        if ($onLeave) {
            $result['late_minutes'] = 0;
            $result['early_leave_minutes'] = 0;
        }

        $result['status'] = match (true) {
            $onLeave => 'on_leave', // approved leave wins over stray punches; worked minutes are still recorded
            ! $last && $final => 'incomplete',
            $result['late_minutes'] > 0 => 'late',
            default => 'present',
        };

        return $result;
    }

    /** Whole minutes from $a to $b, never negative. */
    public static function minutes(CarbonInterface $a, CarbonInterface $b): int
    {
        return max(0, intdiv($b->getTimestamp() - $a->getTimestamp(), 60));
    }
}
