<?php

namespace App\Modules\Payroll\Support;

use App\Models\Holiday;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

class WorkingDays
{
    /** Monday–Friday excluding public / company holidays. */
    public static function between(CarbonInterface $from, CarbonInterface $to, ?int $companyId): int
    {
        $holidays = Holiday::datesBetween($from, $to, $companyId)->flip();
        $count = 0;
        foreach (CarbonPeriod::create($from->copy()->startOfDay(), $to->copy()->startOfDay()) as $day) {
            if (! $day->isWeekend() && ! isset($holidays[$day->toDateString()])) {
                $count++;
            }
        }

        return $count;
    }
}
