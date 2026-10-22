<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Modules\Leave\Dashboard\HandoverApprovalProvider;
use App\Modules\Leave\Dashboard\SupervisorApprovalProvider;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveJobRun;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveWorkflow;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Staff on leave, team calendar, recalls, balance administration and history. */
class LeaveAdminController extends Controller
{
    public function __construct(private LeaveWorkflow $workflow, private LeaveBalanceService $balances) {}

    /**
     * Employee ids the user may see in team views: everyone for leave administrators / report viewers,
     * otherwise the user's direct reports (and themselves).
     */
    public static function visibleEmployeeIds(User $user): ?array
    {
        if ($user->can('leave.manage') || $user->can('leave.reports')) {
            return null;
        }
        $me = $user->employee;

        return $me ? $me->directReports()->pluck('id')->push($me->id)->all() : [];
    }

    /** GET /leave/on-leave — staff on leave on a date (default today). */
    public function onLeave(Request $request): JsonResponse
    {
        $date = $request->date('date') ?? today();
        $ids = static::visibleEmployeeIds($request->user());

        $rows = LeaveRequest::taken()->overlapping($date->toDateString(), $date->toDateString())
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->integer('department_id'))))
            ->when($request->filled('branch_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('branch_id', $request->integer('branch_id'))))
            ->with(LeaveRequest::PRESENT_WITH)->orderBy('end_date')->get()
            ->map(fn (LeaveRequest $r) => $r->present() + ['can_recall' => $this->workflow->canRecall($r, $request->user()) && $r->status === LeaveRequest::APPROVED]);

        return response()->json(['data' => $rows, 'date' => $date->toDateString()]);
    }

    /** GET /leave/calendar — requests in a range for the Year-at-a-glance planner, with stats. */
    public function calendar(Request $request): JsonResponse
    {
        $from = $request->date('from') ?? today()->startOfMonth();
        $to = $request->date('to') ?? today()->endOfMonth();
        abort_if($from->diffInDays($to) > 400, 422, 'Choose a range of at most one year.');
        $ids = static::visibleEmployeeIds($request->user());
        $dept = $request->integer('department_id') ?: null;

        $events = LeaveRequest::where(fn ($w) => $w->whereIn('status', [...LeaveRequest::OPEN, LeaveRequest::APPROVED])
            ->orWhere(fn ($r) => $r->where('status', LeaveRequest::RECALLED)->where('days', '>', 0)))
            ->overlapping($from->toDateString(), $to->toDateString())
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($dept, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $dept)))
            ->with(['employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name', 'leaveType:id,name,color,code'])
            ->orderBy('start_date')->get()
            ->map(fn (LeaveRequest $r) => [
                'id' => $r->id,
                'employee_id' => $r->employee_id,
                'name' => $r->employee?->full_name,
                'photo_url' => $r->employee?->photo_url,
                'department' => $r->employee?->department?->name,
                'leave_type' => $r->leaveType?->name,
                'color' => $r->leaveType?->color,
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date->toDateString(),
                'days' => $r->days,
                'status' => $r->status,
                'status_label' => LeaveRequest::STATUSES[$r->status] ?? $r->status,
            ]);

        $staff = Employee::active()
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))
            ->when($dept, fn ($q) => $q->where('department_id', $dept))->count();
        $today = today()->toDateString();
        $onLeave = $events->filter(fn ($e) => in_array($e['status'], [LeaveRequest::APPROVED, LeaveRequest::RECALLED], true) && $e['start_date'] <= $today && $e['end_date'] >= $today)->unique('employee_id')->count();

        return response()->json([
            'data' => $events->values(),
            'holidays' => Holiday::datesBetween($from, $to, Tenancy::companyId() ?? $request->user()->company_id)->values(),
            'holiday_names' => Holiday::query()->forCompany(Tenancy::companyId() ?? $request->user()->company_id)->get(['name', 'date', 'recurring'])
                ->mapWithKeys(fn ($h) => [($h->recurring ? $h->date->copy()->year($from->year) : $h->date)->toDateString() => $h->name]),
            'stats' => [
                'total_staff' => $staff,
                'on_leave' => $onLeave,
                'available' => max(0, $staff - $onLeave),
                'approved' => $events->where('status', LeaveRequest::APPROVED)->count(),
                'pending' => $events->whereIn('status', LeaveRequest::OPEN)->count(),
            ],
        ]);
    }

    /** GET /leave/recalls — recall history plus approved leave that can still be recalled. */
    public function recalls(Request $request): JsonResponse
    {
        $user = $request->user();
        $ids = static::visibleEmployeeIds($user);
        $scope = fn ($q) => $q->when($ids !== null, fn ($x) => $x->whereIn('employee_id', $ids));

        $history = LeaveRequest::where('status', LeaveRequest::RECALLED)->tap($scope)
            ->with(LeaveRequest::PRESENT_WITH)->orderByDesc('recalled_at')->limit(200)->get()
            ->map(fn (LeaveRequest $r) => $r->present() + [
                'employee_name' => $r->employee?->full_name,
                'leave_type_name' => $r->leaveType?->name,
                'reason' => $r->recall_reason,
            ]);

        $active = LeaveRequest::where('status', LeaveRequest::APPROVED)->whereDate('end_date', '>=', today())->tap($scope)
            ->with(LeaveRequest::PRESENT_WITH)->orderBy('start_date')->get()
            ->filter(fn ($r) => $this->workflow->canRecall($r, $user))
            ->map(fn (LeaveRequest $r) => $r->present() + ['started' => $r->start_date->lte(today())])->values();

        return response()->json(['data' => $history, 'active' => $active]);
    }

    /** POST /leave/recalls — recall a request (by id) or an employee's current/next approved leave. */
    public function storeRecall(Request $request): JsonResponse
    {
        $data = $request->validate([
            'leave_request_id' => ['nullable', 'integer'],
            'employee_id' => ['required_without:leave_request_id', 'nullable'],
            'recall_date' => ['nullable', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if (! empty($data['leave_request_id'])) {
            $leave = LeaveRequest::findOrFail($data['leave_request_id']);
        } else {
            $key = trim((string) $data['employee_id']);
            $employee = Employee::where('employee_number', $key)->orWhere('id', ctype_digit($key) ? (int) $key : 0)->first();
            if (! $employee) {
                throw ValidationException::withMessages(['employee_id' => 'No employee found with that number.']);
            }
            $leave = LeaveRequest::where('employee_id', $employee->id)->where('status', LeaveRequest::APPROVED)
                ->whereDate('end_date', '>=', today())->orderBy('start_date')->first();
            if (! $leave) {
                throw ValidationException::withMessages(['employee_id' => "{$employee->full_name} has no approved leave in progress or upcoming."]);
            }
        }

        $leave = $this->workflow->recall($leave->load('employee', 'leaveType'), $request->user(), $data['recall_date'] ?? null, $data['reason']);

        return response()->json([
            'data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(),
            'message' => "Recalled. {$leave->recalled_days} day(s) credited back to the balance.",
        ], 201);
    }

    /** GET /leave/balances/{balance}/transactions — the ledger behind one balance. */
    public function transactions(int $balanceId): JsonResponse
    {
        $balance = LeaveBalance::with('leaveType:id,name', 'employee:id,first_name,last_name')->findOrFail($balanceId);

        return response()->json([
            'balance' => $balance,
            'data' => $balance->transactions()->with('actor:id,name', 'request:id,start_date,end_date')->orderBy('effective_date')->orderBy('id')->get(),
        ]);
    }

    /** POST /leave/balances/adjust — adjust by employee + type + year (creates the balance row if needed). */
    public function adjust(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'leave_type_id' => ['required', 'integer'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'days' => ['required', 'numeric', 'not_in:0', 'between:-366,366'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        $type = LeaveType::findOrFail($data['leave_type_id']);
        $balance = $this->balances->ensure($employee, $type, (int) $data['year']);
        abort_unless($balance, 422, 'This leave type has no balance for this employee.');
        $this->balances->post($balance, 'adjustment', (float) $data['days'], $data['reason'], $request->user()->id);
        activity('leave')->performedOn($balance)->causedBy($request->user())->withProperties($data)->log('Leave balance adjusted');

        return response()->json(['data' => $balance->refresh(), 'message' => 'Balance adjusted.']);
    }

    /**
     * POST /leave/balances/upload — CSV with columns employee_number, type code, days.
     * mode=adjust adds the days; mode=set adjusts so the available balance equals the days.
     */
    public function upload(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mode' => ['required', 'in:adjust,set'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $handle = fopen($request->file('file')->getRealPath(), 'r');
        $line = 0;
        $applied = 0;
        $errors = [];
        $types = LeaveType::get()->keyBy(fn ($t) => strtoupper($t->code));
        while (($row = fgetcsv($handle)) !== false) {
            $line++;
            $row = array_map(fn ($v) => trim((string) $v, " \t\n\r\0\x0B\xEF\xBB\xBF"), $row);
            if (count(array_filter($row, 'strlen')) === 0) {
                continue;
            }
            if ($line === 1 && ! is_numeric($row[2] ?? null)) {
                continue; // header
            }
            [$number, $code, $days] = array_pad($row, 3, null);
            $employee = Employee::where('employee_number', $number)->first();
            $type = $types[strtoupper((string) $code)] ?? null;
            if (! $employee || ! $type || ! is_numeric($days)) {
                $errors[] = "Line $line: ".(! $employee ? "unknown employee \"$number\"" : (! $type ? "unknown leave type \"$code\"" : 'days must be a number'));

                continue;
            }
            $balance = $this->balances->ensure($employee, $type, (int) $data['year']);
            if (! $balance) {
                $errors[] = "Line $line: {$type->name} does not apply to {$employee->full_name}";

                continue;
            }
            $delta = $data['mode'] === 'set' ? round((float) $days - $balance->available, 2) : (float) $days;
            if ($delta != 0) {
                $this->balances->post($balance, 'adjustment', $delta, $data['reason'] ?: 'Bulk upload ('.$data['mode'].')', $request->user()->id);
            }
            $applied++;
        }
        fclose($handle);
        activity('leave')->causedBy($request->user())->withProperties(['applied' => $applied, 'errors' => count($errors)])->log('Leave balances uploaded');

        return response()->json(['applied' => $applied, 'errors' => $errors, 'message' => "$applied row(s) applied".($errors ? ', '.count($errors).' skipped' : '').'.']);
    }

    /** POST /leave/balances/initialise — create missing balances for active employees. */
    public function initialise(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: now()->year;
        $companyId = Tenancy::companyId() ?? $request->user()->company_id;
        $created = $this->balances->initialiseAll($year, $companyId);

        return response()->json(['created' => $created, 'message' => "$created balance(s) created for $year."]);
    }

    /**
     * POST /leave/balances/reconcile — compare every balance row of a year with its ledger.
     * apply=false previews the drift; apply=true rewrites the stored columns from the ledger.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'apply' => ['boolean']]);
        $apply = (bool) ($data['apply'] ?? false);
        $diffs = $this->balances->reconcile((int) $data['year'], Tenancy::companyId() ?? $request->user()->company_id, $apply);
        if ($apply) {
            activity('leave')->causedBy($request->user())->withProperties(['year' => $data['year'], 'fixed' => count($diffs)])->log('Leave balances reconciled');
        }

        return response()->json([
            'data' => $diffs,
            'applied' => $apply,
            'message' => $apply ? count($diffs).' balance(s) corrected from the ledger.' : (count($diffs) ? count($diffs).' balance(s) differ from the ledger.' : 'All balances match the ledger.'),
        ]);
    }

    /** GET /leave/runs — scheduled job history. */
    public function runs(): JsonResponse
    {
        $companyId = Tenancy::companyId();

        return response()->json(['data' => LeaveJobRun::with('company:id,name')
            ->when($companyId, fn ($q) => $q->where(fn ($w) => $w->where('company_id', $companyId)->orWhereNull('company_id')))
            ->orderByDesc('ran_at')->limit(100)->get()]);
    }

    /** GET /leave/counts — sidebar / page badges. */
    public function counts(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'handover' => (new HandoverApprovalProvider)->countFor($user),
            'approvals' => ($user->can('leave.approve') || $user->can('leave.manage')) ? (new SupervisorApprovalProvider)->countFor($user) : 0,
        ]);
    }

    /** GET /leave/employees/{employee}/summary — Leave tab on the employee profile. */
    public function employeeSummary(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        $me = $user->employee;
        abort_unless($user->can('leave.manage') || $user->can('leave.reports')
            || ($me && ((int) $me->id === (int) $employee->id || (int) $employee->primary_supervisor_id === (int) $me->id)), 403);
        $year = $request->integer('year') ?: now()->year;

        return response()->json([
            'year' => $year,
            'balances' => $this->balances->summary($employee, $year),
            'requests' => LeaveRequest::where('employee_id', $employee->id)->with(LeaveRequest::PRESENT_WITH)
                ->orderByDesc('start_date')->limit(100)->get()->map->present(),
            'transactions' => $user->can('leave.manage') ? LeaveTransaction::where('employee_id', $employee->id)
                ->whereHas('balance', fn ($q) => $q->where('year', $year))
                ->with('leaveType:id,name', 'actor:id,name')->orderByDesc('effective_date')->orderByDesc('id')->limit(200)->get() : [],
        ]);
    }

    /** POST /leave/requests/{id}/preview — recount for an amendment (supervisor or handover colleague). */
    public function previewFor(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless($this->workflow->canView($leaveRequest, $request->user()), 403);
        $data = $request->validate([
            'start_date' => LeaveRequestController::dateRules(true),
            'end_date' => [...LeaveRequestController::dateRules(true), 'after_or_equal:start_date'], 'half_day' => ['boolean'],
            'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i'],
        ]);
        LeaveRequestController::assertSpan($data['start_date'], $data['end_date']);
        $result = $this->workflow->assess($leaveRequest->employee, $leaveRequest->leaveType, Carbon::parse($data['start_date']), Carbon::parse($data['end_date']),
            (bool) ($data['half_day'] ?? false), $data['start_time'] ?? $leaveRequest->start_time, $data['end_time'] ?? $leaveRequest->end_time, $leaveRequest, false, false);

        return response()->json($result);
    }
}
