<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDevice;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\PunchRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * API used by biometric terminals (token-authenticated, outside the user session).
 *   POST /api/attendance/device/punches   { punches: [{ employee_number | employee_id, timestamp, direction? }] }
 *   GET  /api/attendance/device/staff     active staff of the device's branch, for enrolment
 *   POST /api/attendance/device/heartbeat
 */
class DeviceApiController extends Controller
{
    public function __construct(private PunchRecorder $recorder, private AttendanceService $service) {}

    private function device(Request $request): AttendanceDevice
    {
        return $request->attributes->get('attendance_device');
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json(['ok' => true, 'device' => $device->name, 'server_time' => now()->toIso8601String(), 'timezone' => config('app.timezone')]);
    }

    public function staff(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $staff = Employee::withoutGlobalScope('company')->where('company_id', $device->company_id)->active()
            ->when($device->branch_id, fn ($q) => $q->where('branch_id', $device->branch_id))
            ->orderBy('employee_number')
            ->get(['id', 'employee_number', 'first_name', 'last_name', 'phone']);

        return response()->json([
            'device' => ['id' => $device->id, 'name' => $device->name, 'branch_id' => $device->branch_id],
            'count' => $staff->count(),
            'data' => $staff->map(fn ($e) => ['id' => $e->id, 'employee_number' => $e->employee_number, 'name' => $e->full_name, 'phone' => $e->phone])->values(),
        ]);
    }

    public function punches(Request $request): JsonResponse
    {
        $device = $this->device($request);
        $data = $request->validate([
            'punches' => ['required', 'array', 'min:1', 'max:500'],
            'punches.*.employee_number' => ['nullable', 'string', 'max:40'],
            'punches.*.employee_id' => ['nullable', 'integer'],
            'punches.*.timestamp' => ['required'],
            'punches.*.direction' => ['nullable', 'in:in,out,auto'],
        ]);

        $numbers = collect($data['punches'])->pluck('employee_number')->filter()->unique()->values()->all();
        $ids = collect($data['punches'])->pluck('employee_id')->filter()->unique()->values()->all();
        $employees = Employee::withoutGlobalScope('company')->where('company_id', $device->company_id)
            ->where(fn ($q) => $q->whereIn('employee_number', $numbers ?: ['__none__'])->orWhereIn('id', $ids ?: [0]))
            ->get(['id', 'company_id', 'branch_id', 'employee_number', 'first_name', 'last_name', 'status']);
        $byNumber = $employees->keyBy('employee_number');
        $byId = $employees->keyBy('id');

        $accepted = 0;
        $duplicates = 0;
        $rejected = [];
        $touched = [];
        $latest = now()->addMinutes(5);

        foreach ($data['punches'] as $i => $p) {
            $employee = ! empty($p['employee_id']) ? $byId->get((int) $p['employee_id']) : $byNumber->get($p['employee_number'] ?? '');
            if (! $employee) {
                $rejected[] = ['index' => $i, 'reason' => 'Unknown employee.'];

                continue;
            }
            try {
                $at = is_numeric($p['timestamp'])
                    ? CarbonImmutable::createFromTimestamp((int) $p['timestamp'], config('app.timezone'))
                    : CarbonImmutable::parse($p['timestamp'], config('app.timezone'));
            } catch (Throwable) {
                $rejected[] = ['index' => $i, 'reason' => 'Invalid timestamp.'];

                continue;
            }
            if ($at->gt($latest) || $at->lt(now()->subDays(62))) {
                $rejected[] = ['index' => $i, 'reason' => 'Timestamp outside the accepted window.'];

                continue;
            }

            $result = $this->recorder->record($employee, $at, $p['direction'] ?? 'auto', 'device', [
                'device_id' => $device->id, 'ip' => $request->ip(),
            ], recompute: false);
            if ($result['duplicate']) {
                $duplicates++;
            } else {
                $accepted++;
                $touched[$employee->id.'|'.$result['punch']->punched_at->toDateString()] = [$employee, $result['punch']->punched_at];
            }
        }

        foreach ($touched as [$employee, $at]) {
            $this->service->computeDay($employee, $at);
        }

        return response()->json([
            'accepted' => $accepted,
            'duplicates' => $duplicates,
            'rejected' => $rejected,
            'server_time' => now()->toIso8601String(),
        ], $accepted || $duplicates ? 200 : 422);
    }
}
