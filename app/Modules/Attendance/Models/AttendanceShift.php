<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendanceShift extends Model
{
    use BelongsToCompany, LogsActivity;

    public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'break_minutes' => 'integer', 'grace_minutes' => 'integer'];
    }

    /**
     * Always ISO weekday numbers (1 = Monday). Older seed data stored day names
     * (["Mon","Tue",...]), which crashed daysLabel(); normalise on read and write.
     */
    protected function workingDays(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? [] : self::parseDays(is_string($value) ? (json_decode($value, true) ?? $value) : $value),
            set: fn ($value) => json_encode(self::parseDays($value ?? [])),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('attendance');
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'attendance_employee_shifts', 'shift_id', 'employee_id')->withTimestamps();
    }

    /** "Mon-Fri", "Mon-Sat", "Mon, Wed, Fri". */
    public function daysLabel(): string
    {
        $days = collect($this->working_days ?? [])->map(fn ($d) => (int) $d)->sort()->values();
        if ($days->isEmpty()) {
            return '—';
        }
        $contiguous = $days->count() > 2 && $days->last() - $days->first() === $days->count() - 1;

        return $contiguous
            ? self::DAY_NAMES[$days->first()].'-'.self::DAY_NAMES[$days->last()]
            : $days->map(fn ($d) => self::DAY_NAMES[$d] ?? null)->filter()->implode(', ');
    }

    /** Parses "Mon-Fri", "Mon,Wed,Fri" or [1,2,3] into ISO weekday numbers. */
    public static function parseDays(mixed $value): array
    {
        if (is_array($value) && collect($value)->contains(fn ($d) => ! is_numeric($d))) {
            $value = implode(',', array_map('strval', $value)); // e.g. ["Mon","Tue"]
        }
        if (is_array($value)) {
            return collect($value)->map(fn ($d) => (int) $d)->filter(fn ($d) => $d >= 1 && $d <= 7)->unique()->sort()->values()->all();
        }
        $lookup = collect(self::DAY_NAMES)->mapWithKeys(fn ($n, $i) => [strtolower($n) => $i]);
        $days = [];
        foreach (preg_split('/\s*,\s*/', strtolower(trim((string) $value))) as $part) {
            if (str_contains($part, '-')) {
                [$a, $b] = array_map(fn ($x) => $lookup[substr(trim($x), 0, 3)] ?? null, explode('-', $part, 2));
                if ($a && $b && $a <= $b) {
                    $days = array_merge($days, range($a, $b));
                }
            } elseif ($d = $lookup[substr($part, 0, 3)] ?? null) {
                $days[] = $d;
            }
        }

        return collect($days)->unique()->sort()->values()->all();
    }
}
