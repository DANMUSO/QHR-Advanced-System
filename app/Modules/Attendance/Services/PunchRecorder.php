<?php

namespace App\Modules\Attendance\Services;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendancePunch;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Stores punches with de-duplication and recomputes the affected day. */
class PunchRecorder
{
    public const DEDUP_SECONDS = 60;

    public function __construct(private AttendanceService $service) {}

    /** Existing punch for the employee within 60 seconds of $at, if any. */
    public function duplicateOf(Employee $employee, CarbonInterface $at): ?AttendancePunch
    {
        return AttendancePunch::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$at->copy()->subSeconds(self::DEDUP_SECONDS - 1), $at->copy()->addSeconds(self::DEDUP_SECONDS - 1)])
            ->first();
    }

    /**
     * @param  array  $meta  device_id, latitude, longitude, accuracy_m, distance_m, ip, note, created_by
     * @return array{punch: AttendancePunch, duplicate: bool}
     */
    public function record(Employee $employee, CarbonInterface $at, string $direction, string $source, array $meta = [], bool $recompute = true): array
    {
        $at = CarbonImmutable::instance($at)->setTimezone(config('app.timezone'))->startOfSecond();
        if ($existing = $this->duplicateOf($employee, $at)) {
            return ['punch' => $existing, 'duplicate' => true];
        }

        $punch = new AttendancePunch(array_merge($meta, [
            'employee_id' => $employee->id,
            'punched_at' => $at,
            'direction' => in_array($direction, ['in', 'out', 'auto'], true) ? $direction : 'auto',
            'source' => $source,
        ]));
        $punch->company_id = $employee->company_id;
        $punch->save();

        if ($recompute) {
            $this->service->computeDay($employee, $at);
        }

        return ['punch' => $punch, 'duplicate' => false];
    }
}
