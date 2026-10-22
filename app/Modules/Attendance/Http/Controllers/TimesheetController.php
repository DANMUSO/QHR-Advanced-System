<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceTimesheet;
use App\Modules\Attendance\Services\HistoryBuilder;
use App\Modules\Attendance\Support\AttendanceAccess;
use App\Modules\Attendance\Support\Presenter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Weekly timesheets built from attendance, submitted by staff and approved by supervisors / HR. */
class TimesheetController extends Controller
{
    public function __construct(private HistoryBuilder $history) {}

    private static function weekStart(?string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value ?: today()->toDateString())->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['week' => ['nullable', 'date'], 'employee_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $employee = $request->filled('employee_id') ? Employee::findOrFail($request->integer('employee_id')) : $user->employee;
        abort_unless($employee, 422, 'No employee record is linked to your account.');
        abort_unless(AttendanceAccess::canView($user, $employee), 403);

        $week = self::weekStart($request->input('week'));
        $days = $this->history->range($employee, $week, $week->endOfWeek(CarbonImmutable::SUNDAY), false);
        $totals = HistoryBuilder::totals($days);
        $sheet = AttendanceTimesheet::with('decider:id,name')->where('employee_id', $employee->id)->whereDate('week_start', $week)->first();

        return response()->json([
            'employee' => Presenter::employee($employee),
            'week' => $week->toDateString(),
            'week_end' => $week->endOfWeek(CarbonImmutable::SUNDAY)->toDateString(),
            'label' => $week->format('j M').' – '.$week->endOfWeek(CarbonImmutable::SUNDAY)->format('j M Y'),
            'entries' => collect($days)->map(fn ($d) => [
                'date' => $d['date'],
                'day_name' => CarbonImmutable::parse($d['date'])->format('l'),
                'day_type' => $d['day_type'],
                'clock_in' => $d['first_in'],
                'clock_out' => $d['last_out'] ?? (($d['first_in'] && ! $d['is_today']) ? 'NA' : null),
                'hours' => $d['worked_minutes'] ? round($d['worked_minutes'] / 60, 2) : null,
                'regular_hours' => $d['regular_minutes'] ? round($d['regular_minutes'] / 60, 2) : null,
                'overtime_hours' => $d['overtime_minutes'] ? round($d['overtime_minutes'] / 60, 2) : null,
                'late_minutes' => $d['late_minutes'],
                'status' => $d['status'],
                'status_label' => $d['status_label'] ?? null,
                'is_today' => $d['is_today'],
            ])->values(),
            'summary' => [
                'total_hours' => $totals['worked_hours'],
                'regular_hours' => round($totals['regular_minutes'] / 60, 1),
                'overtime_hours' => $totals['overtime_hours'],
                'days_present' => $totals['days_present'],
                'late_count' => $totals['late_count'],
                'absent_count' => $totals['absent_count'],
                'status' => $sheet?->status ?? 'not_submitted',
                'status_label' => ['submitted' => 'Submitted', 'approved' => 'Approved', 'rejected' => 'Rejected'][$sheet?->status] ?? 'Not submitted',
            ],
            'timesheet' => $sheet ? $this->present($sheet) : null,
            'can_submit' => $user->employee?->id === $employee->id
                && $week->lte(today()) && (! $sheet || $sheet->status === 'rejected'),
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate(['week' => ['required', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'No employee record is linked to your account.');
        $week = self::weekStart($data['week']);
        abort_if($week->gt(today()), 422, 'You cannot submit a future week.');

        $sheet = AttendanceTimesheet::where('employee_id', $employee->id)->whereDate('week_start', $week)->first();
        abort_if($sheet && $sheet->status !== 'rejected', 422, 'This week has already been '.($sheet?->status === 'approved' ? 'approved' : 'submitted').'.');

        $totals = HistoryBuilder::totals($this->history->range($employee, $week, $week->endOfWeek(CarbonImmutable::SUNDAY), false));
        $sheet ??= new AttendanceTimesheet(['employee_id' => $employee->id, 'week_start' => $week->toDateString()]);
        $sheet->forceFill([
            'company_id' => $employee->company_id,
            'status' => 'submitted',
            'worked_minutes' => $totals['worked_minutes'],
            'regular_minutes' => $totals['regular_minutes'],
            'overtime_minutes' => $totals['overtime_minutes'],
            'note' => $data['note'] ?? null,
            'submitted_at' => now(),
            'decided_by' => null, 'decided_at' => null, 'reason' => null,
        ])->save();
        activity('attendance')->performedOn($sheet)->causedBy($request->user())->log('Timesheet submitted');

        return response()->json(['message' => 'Timesheet submitted for approval.', 'data' => $this->present($sheet)], 201);
    }

    /** Timesheets awaiting (or past) decisions for the user's team. */
    public function approvals(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['submitted', 'approved', 'rejected'])], 'search' => ['nullable', 'string', 'max:100']]);
        $q = AttendanceTimesheet::with(['employee:id,first_name,last_name,employee_number,photo_path,department_id', 'employee.department:id,name', 'decider:id,name'])
            ->when($f['status'] ?? 'submitted', fn ($q, $s) => $q->where('status', $s))
            ->whereHas('employee', fn ($e) => $e->search($f['search'] ?? null))
            ->orderByDesc('week_start');
        if (! AttendanceAccess::managesAll($request->user())) {
            $q->whereIn('employee_id', AttendanceAccess::teamQuery($request->user())->pluck('id'));
        }
        $q->where('employee_id', '!=', $request->user()->employee?->id ?? 0);

        return response()->json($q->paginate(25)->through(fn ($t) => $this->present($t)));
    }

    public function approve(Request $request, AttendanceTimesheet $timesheet): JsonResponse
    {
        return $this->decide($request, $timesheet, 'approved');
    }

    public function reject(Request $request, AttendanceTimesheet $timesheet): JsonResponse
    {
        return $this->decide($request, $timesheet, 'rejected');
    }

    private function decide(Request $request, AttendanceTimesheet $timesheet, string $status): JsonResponse
    {
        $data = $request->validate(['reason' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'max:500']]);
        abort_unless(AttendanceAccess::canDecide($request->user(), $timesheet->employee), 403, 'You cannot decide on this timesheet.');
        abort_unless($timesheet->status === 'submitted', 422, 'This timesheet has already been '.$timesheet->status.'.');
        $timesheet->forceFill(['status' => $status, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'reason' => $data['reason'] ?? null])->save();
        activity('attendance')->performedOn($timesheet)->causedBy($request->user())->withProperties(['reason' => $data['reason'] ?? null])->log('Timesheet '.$status);
        Presenter::notify($timesheet->employee, new \App\Modules\Attendance\Notifications\AttendanceDecisionNotification('timesheet', $status,
            'timesheet for '.$timesheet->week_start->format('j M').' – '.$timesheet->week_start->copy()->addDays(6)->format('j M Y'), $data['reason'] ?? null, $request->user()->name));

        return response()->json(['message' => 'Timesheet '.$status.'.', 'data' => $this->present($timesheet->load('employee', 'decider:id,name'))]);
    }

    private function present(AttendanceTimesheet $t): array
    {
        return [
            'id' => $t->id,
            'employee' => $t->relationLoaded('employee') ? Presenter::employee($t->employee) : null,
            'employee_name' => $t->relationLoaded('employee') ? $t->employee?->full_name : null,
            'week_start' => $t->week_start->toDateString(),
            'week_label' => $t->week_start->format('j M').' – '.$t->week_start->copy()->addDays(6)->format('j M Y'),
            'status' => $t->status,
            'total_hours' => round($t->worked_minutes / 60, 1),
            'regular_hours' => round($t->regular_minutes / 60, 1),
            'overtime_hours' => round($t->overtime_minutes / 60, 1),
            'note' => $t->note,
            'submitted_at' => $t->submitted_at,
            'decided_by' => $t->relationLoaded('decider') ? $t->decider?->name : null,
            'decided_at' => $t->decided_at,
            'reason' => $t->reason,
        ];
    }
}
