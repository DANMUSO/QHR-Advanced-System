<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Attendance analytics: lateness ranking, absenteeism, overtime by department and month, punctuality trend. */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'company_id' => ['nullable', 'integer'],
        ]);
        $from = CarbonImmutable::parse($f['from'] ?? today()->subDays(29)->toDateString());
        $to = CarbonImmutable::parse($f['to'] ?? today()->toDateString());
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of at most one year.');

        $base = fn (): Builder => AttendanceDay::query()
            ->join('employees', 'employees.id', '=', 'attendance_days.employee_id')
            ->whereBetween('attendance_days.date', [$from->toDateString(), $to->toDateString()])
            ->when(Tenancy::isGlobal() && ! empty($f['company_id']), fn ($q) => $q->where('attendance_days.company_id', $f['company_id']))
            ->when($f['branch_id'] ?? null, fn ($q, $b) => $q->where('employees.branch_id', $b))
            ->when($f['department_id'] ?? null, fn ($q, $d) => $q->where('employees.department_id', $d));

        $present = "attendance_days.status IN ('present','late','incomplete')";

        $k = $base()->toBase()->selectRaw("
            SUM(CASE WHEN $present AND attendance_days.day_type = 'working' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN attendance_days.status = 'late' THEN 1 ELSE 0 END) as late_days,
            SUM(CASE WHEN attendance_days.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN attendance_days.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days,
            SUM(CASE WHEN attendance_days.status = 'incomplete' THEN 1 ELSE 0 END) as incomplete_days,
            SUM(attendance_days.worked_minutes) as worked,
            SUM(attendance_days.overtime_minutes) as overtime,
            SUM(CASE WHEN attendance_days.worked_minutes > 0 THEN 1 ELSE 0 END) as worked_days
        ")->first();
        $expected = (int) $k->present_days + (int) $k->absent_days;

        $lateness = $base()->toBase()
            ->where('attendance_days.late_minutes', '>', 0)
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.employee_number')
            ->selectRaw('employees.id, employees.first_name, employees.last_name, employees.employee_number, COUNT(*) as late_count, SUM(attendance_days.late_minutes) as late_minutes, ROUND(AVG(attendance_days.late_minutes)) as avg_late')
            ->orderByDesc('late_count')->orderByDesc('late_minutes')->limit(15)->get()
            ->map(fn ($r) => ['employee_id' => $r->id, 'name' => trim($r->first_name.' '.$r->last_name), 'employee_number' => $r->employee_number,
                'late_count' => (int) $r->late_count, 'late_minutes' => (int) $r->late_minutes, 'avg_late_minutes' => (int) $r->avg_late]);

        $absentees = $base()->toBase()
            ->where('attendance_days.status', 'absent')
            ->groupBy('employees.id', 'employees.first_name', 'employees.last_name', 'employees.employee_number')
            ->selectRaw('employees.id, employees.first_name, employees.last_name, employees.employee_number, COUNT(*) as absent_days')
            ->orderByDesc('absent_days')->limit(15)->get()
            ->map(fn ($r) => ['employee_id' => $r->id, 'name' => trim($r->first_name.' '.$r->last_name), 'employee_number' => $r->employee_number, 'absent_days' => (int) $r->absent_days]);

        $byDepartment = $base()->toBase()
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->groupBy('departments.id', 'departments.name')
            ->selectRaw("departments.name as department,
                SUM(CASE WHEN $present AND attendance_days.day_type = 'working' THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN attendance_days.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                SUM(CASE WHEN attendance_days.status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(attendance_days.overtime_minutes) as overtime")
            ->orderBy('departments.name')->get()
            ->map(function ($r) {
                $exp = (int) $r->present_days + (int) $r->absent_days;

                return ['department' => $r->department ?? 'Unassigned', 'present_days' => (int) $r->present_days, 'absent_days' => (int) $r->absent_days,
                    'late_days' => (int) $r->late_days, 'overtime_hours' => round($r->overtime / 60, 1),
                    'absence_rate' => $exp ? round($r->absent_days / $exp * 100, 1) : 0,
                    'punctuality' => $r->present_days ? round(($r->present_days - $r->late_days) / $r->present_days * 100, 1) : null];
            });

        $monthExpr = DB::connection()->getDriverName() === 'sqlite' ? "strftime('%Y-%m', attendance_days.date)" : "DATE_FORMAT(attendance_days.date, '%Y-%m')";
        $overtimeRows = $base()->toBase()
            ->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
            ->where('attendance_days.overtime_minutes', '>', 0)
            ->groupBy('departments.name', DB::raw($monthExpr))
            ->selectRaw("COALESCE(departments.name, 'Unassigned') as department, $monthExpr as month, SUM(attendance_days.overtime_minutes) as minutes")
            ->get();
        $months = $overtimeRows->pluck('month')->unique()->sort()->values();
        $overtime = [
            'months' => $months->map(fn ($m) => ['key' => $m, 'label' => CarbonImmutable::createFromFormat('Y-m-d', $m.'-01')->format('M Y')])->values(),
            'series' => $overtimeRows->groupBy('department')->map(fn ($rows, $dept) => [
                'department' => $dept,
                'hours' => $months->map(fn ($m) => round(($rows->firstWhere('month', $m)->minutes ?? 0) / 60, 1))->values(),
                'total' => round($rows->sum('minutes') / 60, 1),
            ])->sortByDesc('total')->values(),
        ];

        $weekly = $from->diffInDays($to) > 62;
        $trendRows = $base()->toBase()->where('attendance_days.day_type', 'working')
            ->groupBy('attendance_days.date')
            ->selectRaw("attendance_days.date as d,
                SUM(CASE WHEN $present THEN 1 ELSE 0 END) as present_days,
                SUM(CASE WHEN attendance_days.status = 'late' THEN 1 ELSE 0 END) as late_days,
                SUM(CASE WHEN attendance_days.status = 'absent' THEN 1 ELSE 0 END) as absent_days")
            ->orderBy('d')->get()
            ->groupBy(fn ($r) => $weekly ? CarbonImmutable::parse($r->d)->startOfWeek()->toDateString() : substr($r->d, 0, 10));
        $trend = $trendRows->map(function ($rows, $key) use ($weekly) {
            $p = $rows->sum('present_days');
            $l = $rows->sum('late_days');
            $a = $rows->sum('absent_days');

            return ['date' => $key, 'label' => ($weekly ? 'Wk ' : '').CarbonImmutable::parse($key)->format('j M'),
                'punctuality' => $p ? round(($p - $l) / $p * 100, 1) : null,
                'attendance' => ($p + $a) ? round($p / ($p + $a) * 100, 1) : null,
                'present' => (int) $p, 'late' => (int) $l, 'absent' => (int) $a];
        })->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'kpis' => [
                'attendance_rate' => $expected ? round($k->present_days / $expected * 100, 1) : null,
                'punctuality_rate' => $k->present_days ? round(($k->present_days - $k->late_days) / $k->present_days * 100, 1) : null,
                'late_days' => (int) $k->late_days,
                'absent_days' => (int) $k->absent_days,
                'leave_days' => (int) $k->leave_days,
                'incomplete_days' => (int) $k->incomplete_days,
                'avg_hours_per_day' => $k->worked_days ? round($k->worked / $k->worked_days / 60, 2) : null,
                'overtime_hours' => round(($k->overtime ?? 0) / 60, 1),
            ],
            'lateness' => $lateness,
            'absenteeism' => ['employees' => $absentees, 'departments' => $byDepartment],
            'overtime' => $overtime,
            'trend' => ['interval' => $weekly ? 'week' : 'day', 'points' => $trend],
        ]);
    }
}
