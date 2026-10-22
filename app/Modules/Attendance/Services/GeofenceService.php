<?php

namespace App\Modules\Attendance\Services;

use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceGeofence;
use Illuminate\Support\Collection;

/** Checks web / mobile clock-ins against the active zones of the employee's branch (or company-wide zones). */
class GeofenceService
{
    /** GPS accuracy credited to the user, capped so a vague fix cannot pass from far away. */
    public const MAX_ACCURACY_ALLOWANCE = 50;

    /** @return Collection<int, AttendanceGeofence> */
    public function zonesFor(Employee $employee): Collection
    {
        return AttendanceGeofence::withoutGlobalScope('company')
            ->where('company_id', $employee->company_id)->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($employee->branch_id, fn ($b) => $b->orWhere('branch_id', $employee->branch_id)))
            ->get();
    }

    /**
     * @return array{required: bool, inside: bool, distance_m: ?int, zone: ?AttendanceGeofence, message: ?string}
     */
    public function check(Employee $employee, ?float $lat, ?float $lng, ?float $accuracy = null): array
    {
        $zones = $this->zonesFor($employee);
        if ($zones->isEmpty()) {
            return ['required' => false, 'inside' => true, 'distance_m' => null, 'zone' => null, 'message' => null];
        }
        if ($lat === null || $lng === null) {
            return ['required' => true, 'inside' => false, 'distance_m' => null, 'zone' => null,
                'message' => 'Your location is required to clock in. Allow location access and try again.'];
        }

        $allowance = min(max(0, (float) $accuracy), self::MAX_ACCURACY_ALLOWANCE);
        $nearest = $zones->map(fn ($z) => ['zone' => $z, 'distance' => $z->distanceTo($lat, $lng)])->sortBy('distance')->first();
        $inside = $nearest['distance'] - $allowance <= $nearest['zone']->radius_m;

        return [
            'required' => true,
            'inside' => $inside,
            'distance_m' => (int) round($nearest['distance']),
            'zone' => $nearest['zone'],
            'message' => $inside ? null : sprintf('You are %s from %s (allowed radius %d m). Move closer to clock in.',
                $nearest['distance'] >= 1000 ? round($nearest['distance'] / 1000, 1).' km' : round($nearest['distance']).' m',
                $nearest['zone']->name, $nearest['zone']->radius_m),
        ];
    }
}
