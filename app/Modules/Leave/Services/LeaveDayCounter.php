<?php

namespace App\Modules\Leave\Services;

use App\Models\Company;
use App\Models\Holiday;
use App\Modules\Leave\Models\LeaveType;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

/**
 * The single day-counting rule used on apply, edit, amend, recall and payroll (HR-FEATURES §3.2 [Fix]).
 *  - inclusive dates (end − start + 1)
 *  - working-day types skip weekends and company/public holidays
 *  - calendar-day types (maternity, paternity) count every day
 *  - a half day takes 0.5 off the last working day
 *  - hourly leave = hours ÷ the company's hours_per_day
 */
class LeaveDayCounter
{
    public const DEFAULT_WEEKEND = [0, 6]; // Carbon dayOfWeek: Sunday, Saturday

    /** Longest range the counter will walk (two years; a single request is limited to one in the controllers). */
    public const MAX_SPAN_DAYS = 731;

    /**
     * Pure counting function (no database access) — unit tested.
     *
     * @param  array<string>  $holidays  Y-m-d dates
     * @param  array<int>  $weekend  Carbon dayOfWeek numbers
     */
    public static function countDays(CarbonInterface $start, CarbonInterface $end, string $counting = 'working', array $holidays = [], bool $halfDay = false, array $weekend = self::DEFAULT_WEEKEND): array
    {
        $start = Carbon::parse($start->toDateString());
        $end = Carbon::parse($end->toDateString());
        if ($end->lt($start)) {
            return ['days' => 0.0, 'calendar_days' => 0, 'working_days' => 0, 'weekend_days' => 0, 'holidays' => []];
        }
        // Day-by-day counting: refuse absurd ranges (e.g. a mistyped year) rather than tie up the request for minutes.
        if ($start->diffInDays($end) > self::MAX_SPAN_DAYS) {
            throw new \InvalidArgumentException('A leave period cannot span more than '.self::MAX_SPAN_DAYS.' days.');
        }

        $holidaySet = array_flip($holidays);
        $calendar = 0;
        $working = 0;
        $weekendDays = 0;
        $hit = [];
        foreach (CarbonPeriod::create($start, $end) as $day) {
            $calendar++;
            $date = $day->toDateString();
            if (in_array($day->dayOfWeek, $weekend, true)) {
                $weekendDays++;
            } elseif (isset($holidaySet[$date])) {
                $hit[] = $date;
            } else {
                $working++;
            }
        }

        $days = $counting === 'calendar' ? $calendar : $working;
        if ($halfDay && $days > 0) {
            $days -= 0.5;
        }

        return [
            'days' => (float) $days,
            'calendar_days' => $calendar,
            'working_days' => $working,
            'weekend_days' => $weekendDays,
            'holidays' => $hit,
        ];
    }

    public static function hoursBetween(?string $from, ?string $to): float
    {
        if (! $from || ! $to) {
            return 0.0;
        }
        $a = Carbon::createFromFormat('H:i', substr($from, 0, 5));
        $b = Carbon::createFromFormat('H:i', substr($to, 0, 5));

        return $b->gt($a) ? round($a->diffInMinutes($b) / 60, 2) : 0.0;
    }

    public static function hoursToDays(float $hours, float $hoursPerDay): float
    {
        return $hoursPerDay > 0 ? round($hours / $hoursPerDay, 2) : 0.0;
    }

    /** Holiday dates for a company and range. */
    public function holidays(CarbonInterface $from, CarbonInterface $to, ?int $companyId): array
    {
        return Holiday::datesBetween($from, $to, $companyId)->all();
    }

    public function weekend(?Company $company): array
    {
        return array_map('intval', (array) ($company?->setting('weekend_days', self::DEFAULT_WEEKEND) ?? self::DEFAULT_WEEKEND));
    }

    /**
     * Count a request for a leave type. Returns days plus a breakdown for the preview.
     */
    public function forType(LeaveType $type, Company $company, CarbonInterface $start, CarbonInterface $end, bool $halfDay = false, ?string $startTime = null, ?string $endTime = null): array
    {
        $weekend = $this->weekend($company);
        $holidays = $this->holidays($start, $end, $company->id);
        $base = static::countDays($start, $end, $type->counting, $holidays, $halfDay && $type->allow_half_day, $weekend);
        $hours = null;

        if ($type->allow_hours && ($startTime || $endTime)) {
            $hours = static::hoursBetween($startTime, $endTime);
            $hoursPerDay = (float) $company->setting('hours_per_day', 8);
            $isWorkingDay = $base['working_days'] > 0;
            $base['days'] = $isWorkingDay ? min(1.0, static::hoursToDays($hours, $hoursPerDay)) : 0.0;
            $base['hours_per_day'] = $hoursPerDay;
        }

        return $base + [
            'hours' => $hours,
            'counting' => $type->counting,
            'return_date' => $this->nextWorkingDay($end, $company)->toDateString(),
        ];
    }

    /** First working day after the given date (the "report back" date). */
    public function nextWorkingDay(CarbonInterface $date, Company $company): Carbon
    {
        $weekend = $this->weekend($company);
        $day = Carbon::parse($date->toDateString())->addDay();
        $holidays = array_flip($this->holidays($day, $day->copy()->addDays(30), $company->id));
        // Bounded: a misconfigured company marking all seven days as weekend must not loop forever.
        for ($i = 0; $i < 31 && (in_array($day->dayOfWeek, $weekend, true) || isset($holidays[$day->toDateString()])); $i++) {
            $day->addDay();
        }

        return $day;
    }
}
