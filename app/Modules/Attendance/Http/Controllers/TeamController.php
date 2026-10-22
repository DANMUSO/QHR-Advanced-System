<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Support\AttendanceAccess;
use App\Modules\Attendance\Support\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public const GROUPS = ['present', 'late', 'not_in', 'absent', 'on_leave', 'day_off'];

    public function __construct(private AttendanceService $service) {}

    /** Live attendance for direct reports (supervisors) or the whole company (HR). */
    public function today(Request $request): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date', 'before_or_equal:today'], 'branch_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100']]);
        $user = $request->user();
        $date = CarbonImmutable::parse($request->input('date', today()->toDateString()));

        $employees = AttendanceAccess::teamQuery($user)
            ->when($request->integer('branch_id'), fn ($q, $b) => $q->where('branch_id', $b))
            ->when($request->integer('department_id'), fn ($q, $d) => $q->where('department_id', $d))
            ->search($request->input('search'))
            ->with(['branch:id,name', 'department:id,name', 'position:id,title'])
            ->orderBy('first_name')
            ->get(['id', 'company_id', 'employee_number', 'first_name', 'last_name', 'photo_path', 'branch_id', 'department_id', 'position_id', 'phone', 'primary_supervisor_id', 'secondary_supervisor_id']);

        $statuses = $this->service->liveStatuses($employees, $date);
        $members = $employees->map(function ($e) use ($statuses) {
            $s = $statuses[$e->id];

            return array_merge(Presenter::employee($e), [
                'position' => $e->position?->title,
                'phone' => $e->phone,
                'status' => $s['status'],
                'first_in' => Presenter::time($s['first_in']),
                'last_out' => Presenter::time($s['last_out']),
                'late_minutes' => $s['late_minutes'],
                'worked_minutes' => $s['worked_minutes'],
            ]);
        })->values();

        $counts = collect(self::GROUPS)->mapWithKeys(fn ($g) => [$g => $members->where('status', $g)->count()]);

        return response()->json([
            'date' => $date->toDateString(),
            'scope' => AttendanceAccess::managesAll($user) ? 'company' : 'team',
            'total' => $members->count(),
            'counts' => $counts,
            'summary' => [
                'present' => $counts['present'] + $counts['late'],
                'late' => $counts['late'],
                'absent' => $counts['absent'],
                'on_leave' => $counts['on_leave'],
                'not_in' => $counts['not_in'],
            ],
            'data' => $members,
        ]);
    }
}
