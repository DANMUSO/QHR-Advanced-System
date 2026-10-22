<?php

namespace App\Modules\Attendance\Support;

use App\Models\Company;
use App\Modules\Attendance\Models\AttendanceShift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Working-time rules for one employee: the assigned shift if any, otherwise the company settings (with defaults).
 * Plain value object so the DayCalculator never touches the database.
 */
final class AttendanceRules
{
    public const DEFAULTS = [
        'work_start' => '07:45',
        'work_end' => '17:00',
        'late_grace_minutes' => 0,
        'break_minutes' => 0,
        'hours_per_day' => 8,
        'working_days' => [1, 2, 3, 4, 5],   // ISO weekday numbers; others are weekend
        'web_clock_enabled' => true,
        'overtime_min_minutes' => 30,         // overtime below this is not claimed
        'overtime_multiplier_normal' => 1.5,
        'overtime_multiplier_holiday' => 2.0,
    ];

    /** Company setting keys this module reads and edits. */
    public const SETTING_KEYS = ['work_start', 'work_end', 'late_grace_minutes', 'break_minutes', 'hours_per_day', 'working_days',
        'web_clock_enabled', 'overtime_min_minutes', 'overtime_multiplier_normal', 'overtime_multiplier_holiday'];

    public function __construct(
        public readonly string $workStart = '07:45',
        public readonly string $workEnd = '17:00',
        public readonly int $graceMinutes = 0,
        public readonly int $breakMinutes = 0,
        public readonly float $hoursPerDay = 8,
        public readonly array $workingDays = [1, 2, 3, 4, 5],
        public readonly bool $webClockEnabled = true,
        public readonly int $overtimeMinMinutes = 30,
        public readonly float $overtimeRateNormal = 1.5,
        public readonly float $overtimeRateHoliday = 2.0,
        public readonly ?int $shiftId = null,
        public readonly ?string $shiftName = null,
    ) {}

    public static function fromArray(array $s, ?int $shiftId = null, ?string $shiftName = null): self
    {
        $s = array_replace(self::DEFAULTS, array_filter($s, fn ($v) => $v !== null));

        return new self(
            substr((string) $s['work_start'], 0, 5),
            substr((string) $s['work_end'], 0, 5),
            max(0, (int) $s['late_grace_minutes']),
            max(0, (int) $s['break_minutes']),
            (float) $s['hours_per_day'],
            array_values(array_map('intval', (array) $s['working_days'])),
            filter_var($s['web_clock_enabled'], FILTER_VALIDATE_BOOL),
            max(0, (int) $s['overtime_min_minutes']),
            (float) $s['overtime_multiplier_normal'],
            (float) $s['overtime_multiplier_holiday'],
            $shiftId,
            $shiftName,
        );
    }

    public static function companyValues(?Company $company): array
    {
        $values = [];
        foreach (self::SETTING_KEYS as $key) {
            $values[$key] = $company ? $company->setting($key, self::DEFAULTS[$key]) : self::DEFAULTS[$key];
        }

        return $values;
    }

    public static function forCompany(?Company $company, ?AttendanceShift $shift = null): self
    {
        $values = self::companyValues($company);
        if ($shift) {
            $values['work_start'] = $shift->start_time;
            $values['work_end'] = $shift->end_time;
            $values['break_minutes'] = $shift->break_minutes;
            $values['working_days'] = $shift->working_days ?: $values['working_days'];
            if ($shift->grace_minutes !== null) {
                $values['late_grace_minutes'] = $shift->grace_minutes;
            }
        }

        return self::fromArray($values, $shift?->id, $shift?->name);
    }

    public function startAt(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$this->workStart, $date->getTimezone());
    }

    public function endAt(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$this->workEnd, $date->getTimezone());
    }

    public function isWorkingDay(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->workingDays, true);
    }

    public function toArray(): array
    {
        return [
            'work_start' => $this->workStart,
            'work_end' => $this->workEnd,
            'late_grace_minutes' => $this->graceMinutes,
            'break_minutes' => $this->breakMinutes,
            'hours_per_day' => $this->hoursPerDay,
            'working_days' => $this->workingDays,
            'web_clock_enabled' => $this->webClockEnabled,
            'overtime_min_minutes' => $this->overtimeMinMinutes,
            'overtime_multiplier_normal' => $this->overtimeRateNormal,
            'overtime_multiplier_holiday' => $this->overtimeRateHoliday,
            'shift' => $this->shiftId ? ['id' => $this->shiftId, 'name' => $this->shiftName] : null,
        ];
    }
}
