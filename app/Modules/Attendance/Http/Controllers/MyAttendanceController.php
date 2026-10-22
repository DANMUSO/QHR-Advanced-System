<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\GeofenceService;
use App\Modules\Attendance\Services\HistoryBuilder;
use App\Modules\Attendance\Services\PunchRecorder;
use App\Modules\Attendance\Support\AttendanceAccess;
use App\Modules\Attendance\Support\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyAttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $service,
        private HistoryBuilder $history,
        private GeofenceService $geofences,
        private PunchRecorder $recorder,
    ) {}

    private function me(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'No employee record is linked to your account.');

        return $employee;
    }

    /** Days in a range (defaults to the current week). */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $from = CarbonImmutable::parse($request->input('from', now()->startOfWeek()->toDateString()));
        $to = CarbonImmutable::parse($request->input('to', now()->endOfWeek()->toDateString()));
        abort_if($from->diffInDays($to) > 92, 422, 'The range may not exceed 92 days.');
        $days = $this->history->range($employee, $from, $to, false);

        return response()->json(['data' => $days, 'totals' => HistoryBuilder::totals($days)]);
    }

    /** Clock panel: today's punches, live summary, next action and geofence. */
    public function today(Request $request): JsonResponse
    {
        $employee = $this->me($request)->loadMissing('branch:id,name');
        $today = CarbonImmutable::today();
        $punches = AttendancePunch::where('employee_id', $employee->id)->with('device:id,name')
            ->whereBetween('punched_at', [$today->startOfDay(), $today->endOfDay()])->orderBy('punched_at')->get();
        $r = $this->service->evaluate($employee, $today, $punches, null, false);
        $rules = $r['rules'];
        $last = $punches->last();
        $zones = $this->geofences->zonesFor($employee);

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'employee' => ['id' => $employee->id, 'name' => $employee->full_name, 'branch' => $employee->branch?->name],
            'punches' => $punches->map(fn ($p) => Presenter::punch($p))->values(),
            'summary' => [
                'day_type' => $r['day_type'],
                'status' => $r['punch_count'] ? $r['status'] : ($r['on_leave'] ? 'on_leave' : ($r['day_type'] !== 'working' ? $r['day_type'] : 'not_in')),
                'first_in' => Presenter::time($r['first_in']),
                'last_out' => Presenter::time($r['last_out']),
                'late_minutes' => $r['late_minutes'],
                'worked_minutes' => $r['last_out'] ? $r['worked_minutes'] : ($r['first_in'] ? max(0, (int) floor($r['first_in']->diffInMinutes(now(), true))) : 0),
                'overtime_minutes' => $r['overtime_minutes'],
                'on_leave' => $r['on_leave'],
            ],
            'clocked_in' => $last && $last->direction !== 'out' && ! ($r['last_out'] && $last->punched_at->eq($r['last_out'])),
            'next_direction' => ($last && $last->direction !== 'out' && ! ($r['last_out'] && $last->punched_at->eq($r['last_out']))) ? 'out' : 'in',
            'rules' => $rules->toArray(),
            'web_clock_enabled' => $rules->webClockEnabled,
            'geofence' => [
                'required' => $zones->isNotEmpty(),
                'zones' => $zones->map(fn ($z) => ['id' => $z->id, 'name' => $z->name, 'latitude' => $z->latitude, 'longitude' => $z->longitude, 'radius_m' => $z->radius_m])->values(),
            ],
        ]);
    }

    /** Web / mobile clock-in and clock-out. */
    public function punch(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $data = $request->validate([
            'direction' => ['nullable', 'in:in,out'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'note' => ['nullable', 'string', 'max:300'],
            'source' => ['nullable', 'in:web,mobile'],
        ]);

        $rules = $this->service->rulesFor($employee);
        abort_unless($rules->webClockEnabled, 403, 'Web clock-in is disabled for your company. Use the biometric terminal.');

        $lat = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $lng = isset($data['longitude']) ? (float) $data['longitude'] : null;
        $accuracy = $data['accuracy_m'] ?? $data['accuracy'] ?? null;
        $geo = $this->geofences->check($employee, $lat, $lng, $accuracy !== null ? (float) $accuracy : null);
        if (! $geo['inside']) {
            return response()->json(['message' => $geo['message'], 'distance_m' => $geo['distance_m'], 'errors' => ['location' => [$geo['message']]]], 422);
        }

        $direction = $data['direction'] ?? null;
        if (! $direction) {
            $last = AttendancePunch::where('employee_id', $employee->id)->whereDate('punched_at', today())->latest('punched_at')->first();
            $direction = $last && $last->direction === 'in' ? 'out' : 'in';
        }

        $result = $this->recorder->record($employee, now(), $direction, $data['source'] ?? 'web', [
            'latitude' => $lat, 'longitude' => $lng, 'accuracy_m' => $accuracy, 'distance_m' => $geo['distance_m'],
            'ip' => $request->ip(), 'note' => $data['note'] ?? null, 'created_by' => $request->user()->id,
        ]);
        if ($result['duplicate']) {
            return response()->json(['message' => 'You already clocked in or out less than a minute ago.'], 422);
        }

        activity('attendance')->performedOn($result['punch'])->causedBy($request->user())
            ->withProperties(['direction' => $direction, 'distance_m' => $geo['distance_m']])
            ->log('Web clock-'.$direction);

        return response()->json([
            'message' => 'Clocked '.$direction.' at '.$result['punch']->punched_at->format('H:i').'.',
            'punched_at' => $result['punch']->punched_at->toIso8601String(),
            'direction' => $direction,
            'punch' => Presenter::punch($result['punch']),
        ], 201);
    }

    /** My month calendar. */
    public function month(Request $request): JsonResponse
    {
        return $this->monthFor($this->me($request), $request);
    }

    /** Month calendar for an employee the user may view (history modal, profile tab). */
    public function employeeMonth(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(AttendanceAccess::canView($request->user(), $employee), 403);

        return $this->monthFor($employee, $request);
    }

    private function monthFor(Employee $employee, Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = CarbonImmutable::createFromFormat('Y-m-d', ($request->input('month') ?: now()->format('Y-m')).'-01')->startOfDay();
        $days = $this->history->range($employee, $month->startOfMonth(), $month->endOfMonth());
        $employee->loadMissing(['branch:id,name', 'department:id,name']);
        $rules = $this->service->rulesFor($employee);

        return response()->json([
            'employee' => Presenter::employee($employee),
            'month' => $month->format('Y-m'),
            'label' => $month->format('F Y'),
            'rules' => $rules->toArray(),
            'days' => $days,
            'totals' => HistoryBuilder::totals(array_filter($days, fn ($d) => $d['date'] <= today()->toDateString())),
        ]);
    }
}
