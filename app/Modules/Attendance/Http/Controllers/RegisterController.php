<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Services\AttendanceService;
use App\Modules\Attendance\Services\HistoryBuilder;
use App\Modules\Attendance\Services\PunchRecorder;
use App\Modules\Attendance\Support\AttendanceAccess;
use App\Modules\Attendance\Support\Presenter;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Daily register, exports and HR corrections. */
class RegisterController extends Controller
{
    public function __construct(private AttendanceService $service, private PunchRecorder $recorder) {}

    private function filters(Request $request): array
    {
        return $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'company_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(AttendanceDay::STATUSES)],
            'flag' => ['nullable', Rule::in(['late', 'early', 'overtime', 'overridden'])],
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', Rule::in(['date', 'first_in', 'last_out', 'worked_minutes', 'late_minutes', 'overtime_minutes', 'employee'])],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
    }

    private function query(Request $request, array $f): Builder
    {
        $from = $f['from'] ?? $f['date_from'] ?? $f['date'] ?? today()->toDateString();
        $to = $f['to'] ?? $f['date_to'] ?? $f['date'] ?? $from;
        $user = $request->user();

        $q = AttendanceDay::query()
            ->whereBetween('attendance_days.date', [$from, $to])
            ->with(['employee:id,first_name,last_name,photo_path,employee_number,branch_id,department_id', 'employee.branch:id,name', 'employee.department:id,name'])
            ->when(Tenancy::isGlobal() && ! empty($f['company_id']), fn ($q) => $q->where('attendance_days.company_id', $f['company_id']))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('attendance_days.employee_id', $id))
            ->when($f['status'] ?? null, fn ($q, $s) => $q->where('attendance_days.status', $s))
            ->when(($f['flag'] ?? null) === 'late', fn ($q) => $q->where('late_minutes', '>', 0))
            ->when(($f['flag'] ?? null) === 'early', fn ($q) => $q->where('early_leave_minutes', '>', 0))
            ->when(($f['flag'] ?? null) === 'overtime', fn ($q) => $q->where('overtime_minutes', '>', 0))
            ->when(($f['flag'] ?? null) === 'overridden', fn ($q) => $q->whereNotNull('override_status'))
            ->whereHas('employee', function ($e) use ($f) {
                $e->when($f['branch_id'] ?? null, fn ($x, $b) => $x->where('branch_id', $b))
                    ->when($f['department_id'] ?? null, fn ($x, $d) => $x->where('department_id', $d))
                    ->search($f['search'] ?? null);
            });

        if (! AttendanceAccess::managesAll($user)) {
            $ids = AttendanceAccess::teamQuery($user)->pluck('id');
            $q->whereIn('attendance_days.employee_id', $ids);
        }

        $sort = $f['sort'] ?? 'date';
        $dir = $f['dir'] ?? ($sort === 'date' ? 'desc' : 'asc');
        if ($sort === 'employee') {
            $q->join('employees as e_sort', 'e_sort.id', '=', 'attendance_days.employee_id')
                ->orderBy('e_sort.first_name', $dir)->orderBy('e_sort.last_name', $dir)->select('attendance_days.*');
        } else {
            $q->orderBy('attendance_days.'.$sort, $dir);
        }

        return $q->orderBy('attendance_days.employee_id');
    }

    private static function summary($rows): array
    {
        return [
            'present' => $rows->whereIn('status', ['present', 'late', 'incomplete'])->count(),
            'late' => $rows->where('status', 'late')->count(),
            'absent' => $rows->where('status', 'absent')->count(),
            'on_leave' => $rows->where('status', 'on_leave')->count(),
            'incomplete' => $rows->where('status', 'incomplete')->count(),
            'not_in' => $rows->where('status', 'not_in')->count(),
            'off' => $rows->whereIn('status', ['weekend', 'holiday'])->count(),
        ];
    }

    private function companyFilter(array $f): ?int
    {
        return Tenancy::isGlobal() ? (! empty($f['company_id']) ? (int) $f['company_id'] : null) : Tenancy::companyId();
    }

    /** Finished days in the range get their absentees written first, whether or not the nightly job ran. */
    private function finalise(array $f, string $from, string $to): void
    {
        $this->service->ensureFinalised(CarbonImmutable::parse($from), CarbonImmutable::parse($to), $this->companyFilter($f));
    }

    /**
     * One day, unpaged (supervisors see their team). Lists everyone employed that day: stored rows plus,
     * for people without one, their live status (not clocked in / absent today, holiday or off).
     */
    public function daily(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $f['date'] ??= today()->toDateString();
        $f['from'] = $f['to'] = $f['date'];
        $this->finalise($f, $f['date'], $f['date']);
        $rows = $this->query($request, $f)->get()->map(fn ($d) => Presenter::day($d));

        $date = CarbonImmutable::parse($f['date']);
        if ($date->lte(today()) && empty($f['flag'])) {
            $rows = $rows->concat($this->unrecorded($request, $f, $date, $rows->pluck('employee_id')->all()));
        }
        $rows = $rows->sortBy(fn ($r) => mb_strtolower($r['employee']['name'] ?? ''))->values();

        return response()->json([
            'data' => $rows,
            'summary' => self::summary($rows),
            'date' => $f['date'],
        ]);
    }

    /** Employees employed on the date who have no stored row, with their live status. */
    private function unrecorded(Request $request, array $f, CarbonImmutable $date, array $exclude): array
    {
        $user = $request->user();
        $employees = $this->service->rosterQuery($date, $this->companyFilter($f))
            ->with(['branch:id,name', 'department:id,name'])
            ->whereNotIn('id', $exclude)
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->whereKey($id))
            ->when($f['branch_id'] ?? null, fn ($x, $b) => $x->where('branch_id', $b))
            ->when($f['department_id'] ?? null, fn ($x, $d) => $x->where('department_id', $d))
            ->search($f['search'] ?? null)
            ->when(! AttendanceAccess::managesAll($user), fn ($q) => $q->whereIn('id', AttendanceAccess::teamQuery($user)->pluck('id')))
            ->get();

        $out = [];
        foreach ($this->service->liveStatuses($employees, $date) as $id => $s) {
            $status = $s['status'] === 'day_off' ? $s['day_type'] : $s['status'];
            if (! empty($f['status']) && $f['status'] !== $status) {
                continue;
            }
            $out[] = [
                'id' => null, 'date' => $date->toDateString(), 'employee_id' => $id,
                'employee' => Presenter::employee($employees->firstWhere('id', $id)),
                'day_type' => $s['day_type'], 'first_in' => Presenter::time($s['first_in']), 'last_out' => Presenter::time($s['last_out']),
                'punch_count' => $s['punch_count'], 'worked_minutes' => $s['worked_minutes'], 'worked_formatted' => Presenter::duration($s['worked_minutes']),
                'late_minutes' => $s['late_minutes'], 'overtime_minutes' => 0, 'overtime_formatted' => null,
                'status' => $status, 'status_label' => HistoryBuilder::statusLabel($status),
                'computed_status' => $status, 'override_status' => null, 'override_reason' => null,
            ];
        }

        return $out;
    }

    /** Paged register over a date range. */
    public function register(Request $request): JsonResponse
    {
        $f = $this->filters($request);
        $from = $f['from'] ?? $f['date_from'] ?? $f['date'] ?? today()->toDateString();
        $this->finalise($f, $from, $f['to'] ?? $f['date_to'] ?? $f['date'] ?? $from);
        $base = $this->query($request, $f);
        $agg = (clone $base)->reorder()->toBase()->selectRaw(
            "COUNT(*) as total_rows,
             SUM(CASE WHEN attendance_days.status IN ('present','late','incomplete') THEN 1 ELSE 0 END) as present,
             SUM(CASE WHEN attendance_days.status = 'late' THEN 1 ELSE 0 END) as late,
             SUM(CASE WHEN attendance_days.status = 'absent' THEN 1 ELSE 0 END) as absent,
             SUM(CASE WHEN attendance_days.status = 'on_leave' THEN 1 ELSE 0 END) as on_leave,
             SUM(CASE WHEN attendance_days.status = 'incomplete' THEN 1 ELSE 0 END) as incomplete,
             SUM(attendance_days.worked_minutes) as worked_minutes,
             SUM(attendance_days.overtime_minutes) as overtime_minutes"
        )->first();

        $page = $base->paginate($f['per_page'] ?? 50)->through(fn ($d) => Presenter::day($d));

        return response()->json(array_merge($page->toArray(), [
            'summary' => collect((array) $agg)->except('total_rows')->map(fn ($v) => (int) $v),
        ]));
    }

    /** Back-compat alias used by older screens. */
    public function report(Request $request): JsonResponse
    {
        return $this->register($request);
    }

    public function export(Request $request): StreamedResponse
    {
        $f = $this->filters($request);
        $from = $f['from'] ?? $f['date_from'] ?? $f['date'] ?? today()->toDateString();
        $this->finalise($f, $from, $f['to'] ?? $f['date_to'] ?? $f['date'] ?? $from);
        $query = $this->query($request, $f);
        $name = 'attendance-'.($f['from'] ?? $f['date_from'] ?? $f['date'] ?? today()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Date', 'Employee No', 'Name', 'Branch', 'Department', 'Check-in', 'Check-out', 'Status', 'Late (min)', 'Early leave (min)', 'Work time', 'Overtime', 'Total', 'Override reason']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $d) {
                    fputcsv($out, [
                        $d->date->toDateString(), $d->employee?->employee_number, $d->employee?->full_name,
                        $d->employee?->branch?->name, $d->employee?->department?->name,
                        Presenter::time($d->first_in) ?? '', $d->first_in && ! $d->last_out ? 'NA' : (Presenter::time($d->last_out) ?? ''),
                        str_replace('_', ' ', $d->status), $d->late_minutes, $d->early_leave_minutes,
                        Presenter::duration($d->regular_minutes) ?? '', Presenter::duration($d->overtime_minutes) ?? '',
                        Presenter::duration($d->worked_minutes) ?? '', $d->override_reason,
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- corrections (attendance.manage)

    public function storePunch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'punched_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toDateTimeString()],
            'direction' => ['required', 'in:in,out'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        $at = CarbonImmutable::parse($data['punched_at'], config('app.timezone'));

        $result = $this->recorder->record($employee, $at, $data['direction'], 'manual', [
            'note' => $data['reason'], 'created_by' => $request->user()->id, 'ip' => $request->ip(),
        ]);
        if ($result['duplicate']) {
            return response()->json(['message' => 'A punch already exists within a minute of that time.', 'errors' => ['punched_at' => ['A punch already exists within a minute of that time.']]], 422);
        }
        activity('attendance')->performedOn($result['punch'])->causedBy($request->user())
            ->withProperties(['employee_id' => $employee->id, 'punched_at' => $at->toDateTimeString(), 'direction' => $data['direction'], 'reason' => $data['reason']])
            ->log('Manual punch added');

        return response()->json(['message' => 'Punch added and the day recomputed.', 'data' => Presenter::punch($result['punch'])], 201);
    }

    public function destroyPunch(Request $request, AttendancePunch $punch): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $punch->forceFill(['deleted_by' => $request->user()->id, 'delete_reason' => $data['reason']])->save();
        $punch->delete();
        activity('attendance')->performedOn($punch)->causedBy($request->user())
            ->withProperties(['employee_id' => $punch->employee_id, 'punched_at' => $punch->punched_at->toDateTimeString(), 'source' => $punch->source, 'reason' => $data['reason']])
            ->log('Punch removed');
        $this->service->computeDay(Employee::withoutGlobalScope('company')->findOrFail($punch->employee_id), $punch->punched_at);

        return response()->json(['message' => 'Punch removed and the day recomputed.']);
    }

    /** Override by day id (legacy) — same rules as overrideDate. */
    public function override(Request $request, AttendanceDay $attendanceDay): JsonResponse
    {
        return $this->applyOverride($request, Employee::findOrFail($attendanceDay->employee_id), $attendanceDay->date->toDateString());
    }

    public function overrideDate(Request $request, Employee $employee, string $date): JsonResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date), 404);

        return $this->applyOverride($request, $employee, $date);
    }

    private function applyOverride(Request $request, Employee $employee, string $date): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(AttendanceDay::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        abort_if($date > today()->toDateString(), 422, 'Future days cannot be overridden.');

        $day = AttendanceDay::firstOrNew(['employee_id' => $employee->id, 'date' => $date]);
        $before = $day->exists ? $day->status : null;
        $day->forceFill([
            'company_id' => $employee->company_id,
            'computed_status' => $day->computed_status ?? 'absent',
            'status' => $day->status ?? 'absent',
            'override_status' => $data['status'] ?? null,
            'override_reason' => ($data['status'] ?? null) ? $data['reason'] : null,
            'overridden_by' => ($data['status'] ?? null) ? $request->user()->id : null,
            'overridden_at' => ($data['status'] ?? null) ? now() : null,
        ])->save();

        $day = $this->service->computeDay($employee, CarbonImmutable::parse($date));
        activity('attendance')->performedOn($employee)->causedBy($request->user())
            ->withProperties(['attendance_day_id' => $day?->id, 'date' => $date, 'from' => $before, 'to' => $data['status'] ?? 'computed', 'reason' => $data['reason']])
            ->log(($data['status'] ?? null) ? 'Attendance status overridden' : 'Attendance override cleared');

        return response()->json([
            'message' => ($data['status'] ?? null) ? 'Attendance record updated.' : 'Override cleared.',
            'data' => $day ? Presenter::day($day->fresh()) : null,
        ]);
    }

    /** Recompute a date or a range (≤ 62 days) for the current company scope. */
    public function compute(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date', 'before_or_equal:today'],
            'from' => ['nullable', 'date', 'before_or_equal:today'],
            'to' => ['nullable', 'date', 'after_or_equal:from', 'before_or_equal:today'],
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? $data['date'] ?? today()->toDateString());
        $to = CarbonImmutable::parse($data['to'] ?? $data['date'] ?? $from->toDateString());
        abort_if($from->diffInDays($to) > 62, 422, 'Recompute at most 62 days at a time.');

        $stats = $this->service->computeRange($from, $to, Tenancy::companyId());
        activity('attendance')->causedBy($request->user())->withProperties($stats + ['from' => $from->toDateString(), 'to' => $to->toDateString()])->log('Attendance recomputed');

        return response()->json(['message' => "Recomputed {$stats['days']} day(s): {$stats['saved']} records, {$stats['absent']} absent.", 'stats' => $stats]);
    }
}
