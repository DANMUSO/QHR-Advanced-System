<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    public function __construct(
        private LeaveWorkflow $workflow,
        private LeaveBalanceService $balances,
    ) {}

    /** My leave requests (employee self-service). */
    public function mine(Request $request): JsonResponse
    {
        $employee = $this->myEmployee($request);
        $year = $request->integer('year') ?: now()->year;

        $requests = LeaveRequest::where('employee_id', $employee->id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->whereYear('start_date', $year)
            ->with(LeaveRequest::PRESENT_WITH)
            ->orderByDesc('start_date')->orderByDesc('id')
            ->get()->map->present();

        $years = LeaveRequest::where('employee_id', $employee->id)->pluck('start_date')
            ->map(fn ($d) => \Carbon\Carbon::parse($d)->year)->push(now()->year)->push(now()->year + 1)->unique()->sortDesc()->values();

        return response()->json(['data' => $requests, 'years' => $years]);
    }

    /** Balances for the current employee. */
    public function balances(Request $request): JsonResponse
    {
        $employee = $this->myEmployee($request);
        $year = $request->integer('year') ?: now()->year;

        return response()->json(['data' => $this->balances->summary($employee, $year)]);
    }

    /** Preview day count before submitting. */
    public function preview(Request $request): JsonResponse
    {
        $employee = $this->myEmployee($request);
        $request->merge(['end_date' => $request->input('end_date') ?: $request->input('start_date')]);
        $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'start_date' => self::dateRules(true),
            'end_date' => [...self::dateRules(true), 'after_or_equal:start_date'],
        ]);
        $type = LeaveType::findOrFail($request->integer('leave_type_id'));
        $start = \Carbon\Carbon::parse($request->input('start_date'));
        $end = \Carbon\Carbon::parse($request->input('end_date'));
        self::assertSpan($start, $end);

        $result = $this->workflow->assess(
            $employee, $type, $start, $end,
            (bool) $request->input('half_day'),
            $request->input('start_time'),
            $request->input('end_time'),
            // Editing: the request being edited neither overlaps itself nor reserves its own days.
            $request->filled('leave_request_id') ? LeaveRequest::where('employee_id', $employee->id)->find($request->integer('leave_request_id')) : null,
            false, true
        );

        return response()->json($result);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->myEmployee($request);
        $data = $this->validated($request);
        $type = LeaveType::findOrFail($data['leave_type_id']);

        $leave = $this->workflow->submit($employee, $type, $data, $request->file('document'), $request->user());

        return response()->json([
            'data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(),
            'message' => 'Leave request submitted.',
        ], 201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless($this->workflow->canView($leaveRequest, $request->user()), 403);

        return response()->json(['data' => $leaveRequest->load(LeaveRequest::PRESENT_WITH)->present()]);
    }

    public function update(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless((int) $leaveRequest->employee_id === (int) $request->user()->employee?->id, 403);
        $data = $this->validated($request);
        $type = LeaveType::findOrFail($data['leave_type_id']);

        $leave = $this->workflow->update($leaveRequest, $type, $data, $request->file('document'), $request->user());

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Leave request updated.']);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        abort_unless((int) $leaveRequest->employee_id === (int) $request->user()->employee?->id || $request->user()->can('leave.manage'), 403);
        $this->workflow->cancel($leaveRequest, $request->user(), $request->input('reason'));

        return response()->json(['message' => 'Leave request cancelled.']);
    }

    public function document(Request $request, LeaveRequest $leaveRequest): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($this->workflow->canView($leaveRequest, $request->user()), 403);
        abort_unless($leaveRequest->document_path && Storage::disk('local')->exists($leaveRequest->document_path), 404, 'Document not found.');

        return Storage::disk('local')->response($leaveRequest->document_path, $leaveRequest->document_name, [], $request->boolean('inline') ? 'inline' : 'attachment');
    }

    // ---- Handover colleague actions ----

    /** Requests where I am a handover colleague. */
    public function handoverRequests(Request $request): JsonResponse
    {
        $employee = $this->myEmployee($request);

        $requests = LeaveRequest::where(fn ($q) => $q->where('handover1_id', $employee->id)->orWhere('handover2_id', $employee->id))
            ->with(LeaveRequest::PRESENT_WITH)
            ->orderByDesc('start_date')->limit(100)->get();
        $mine = fn (LeaveRequest $r) => $r->{'handover'.$r->handoverSlotFor($employee).'_status'};

        // Handovers escalated to me because the colleague has no usable login (or, for leave admins, nobody reachable).
        $escalated = $this->workflow->handoverQueue($request->user())
            ?->whereNotIn('id', $requests->pluck('id')->all() ?: [0])
            ->with(LeaveRequest::PRESENT_WITH)->orderBy('start_date')->limit(100)->get() ?? collect();

        return response()->json([
            'data' => $requests->filter(fn ($r) => $r->status === LeaveRequest::PENDING_HANDOVER && $mine($r) === 'pending')->map->present()
                ->concat($escalated->map(fn ($r) => $r->present() + ['escalated' => true, 'escalated_slot' => $this->workflow->handoverSlotForUser($r, $request->user())]))
                ->values(),
            'history' => $requests->reject(fn ($r) => $r->status === LeaveRequest::PENDING_HANDOVER && $mine($r) === 'pending')
                ->map(fn ($r) => $r->present() + ['my_status' => $mine($r)])->values(),
        ]);
    }

    public function handoverDecide(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'start_date' => self::dateRules(false),
            'end_date' => [...self::dateRules(false), 'required_with:start_date', 'after_or_equal:start_date'],
            'half_day' => ['nullable', 'boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
        ]);
        if ($data['start_date'] ?? null) {
            self::assertSpan($data['start_date'], $data['end_date']);
        }
        if (! $data['approve'] && blank($data['reason'] ?? null)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reason' => 'Give a reason for declining the handover.']);
        }

        if ($data['approve'] && ($data['start_date'] ?? null)) {
            $leave = $this->workflow->handoverAmend($leaveRequest, $request->user(), $data, $data['reason'] ?? null);
        } else {
            $leave = $this->workflow->handoverDecision($leaveRequest, $request->user(), (bool) $data['approve'], $data['reason'] ?? null);
        }

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => $data['approve'] ? 'Handover approved.' : 'Handover rejected.']);
    }

    /** Approvals-inbox endpoint: accept the handover. */
    public function handoverApprove(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leave = $this->workflow->handoverDecision($leaveRequest, $request->user(), true, $request->input('reason'));

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Handover approved.']);
    }

    /** Approvals-inbox endpoint: decline the handover (reason required). */
    public function handoverReject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $leave = $this->workflow->handoverDecision($leaveRequest, $request->user(), false, $data['reason']);

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Handover rejected.']);
    }

    // ---- Supervisor / HR actions ----

    /** Pending approvals for the current supervisor / HR. */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $user->employee;

        $query = LeaveRequest::where('status', LeaveRequest::PENDING_SUPERVISOR)
            ->with(LeaveRequest::PRESENT_WITH)
            ->when($employee, fn ($q) => $q->where('employee_id', '!=', $employee->id));

        if ($user->can('leave.manage')) {
            // HR sees every pending request in the company and can decide any of them.
        } elseif ($scoped = \App\Modules\Leave\Dashboard\SupervisorApprovalProvider::query($user)) {
            // The current stage's approver: supervisor / chain-step person, or a role step the user holds.
            $query = $scoped->with(LeaveRequest::PRESENT_WITH);
        } else {
            return response()->json(['data' => [], 'history' => []]);
        }

        // Recently decided requests of the team (or decided by me at any chain step), for context.
        $history = LeaveRequest::whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::REJECTED, LeaveRequest::RECALLED])
            ->where('decided_at', '>=', now()->subDays(60))
            ->when(! $user->can('leave.manage'), fn ($q) => $q->where(fn ($w) => $w->where('supervisor_id', $employee?->id ?? 0)
                ->orWhere('decided_by', $user->id)
                ->orWhereIn('employee_id', $employee ? $employee->directReports()->pluck('id')->all() : [0])))
            ->with(LeaveRequest::PRESENT_WITH)->orderByDesc('decided_at')->limit(50)->get()->map->present();

        return response()->json([
            'data' => $query->orderBy('start_date')->get()->map(fn ($r) => $r->present() + [
                'is_my_report' => $employee && (int) $r->supervisor_id === (int) $employee->id,
                'can_decide' => $this->workflow->canDecide($r, $user),
                'current_step' => ($step = $this->workflow->currentStep($r)) ? \App\Modules\Organization\Support\ApprovalChain::label($step) : null,
                'pending_since_days' => (int) ($r->updated_at?->diffInDays(now()) ?? 0),
                // Who the request waits on; null approver = nobody reachable, so it sits with HR.
                'approver' => $this->approverLabel($r),
                'unassigned' => ! $r->approver_role && ! \App\Modules\Employees\Support\ApproverResolver::isReachable($r->supervisor),
            ]),
            'history' => $history,
        ]);
    }

    /** "Waiting for Jane Doe", "Waiting for HR Admin" (role step), or unassigned when nobody reachable can decide. */
    private function approverLabel(LeaveRequest $r): string
    {
        if ($r->approver_role) {
            $step = $this->workflow->currentStep($r);

            return 'Waiting for '.($step ? \App\Modules\Organization\Support\ApprovalChain::label($step) : $r->approver_role);
        }

        return \App\Modules\Employees\Support\ApproverResolver::isReachable($r->supervisor)
            ? 'Waiting for '.$r->supervisor->full_name
            : 'No supervisor – HR approves';
    }

    public function approve(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $leave = $this->workflow->approve($leaveRequest, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Leave approved.']);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $leave = $this->workflow->reject($leaveRequest, $request->user(), $data['reason']);

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Leave rejected.']);
    }

    public function amendAndApprove(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate([
            'start_date' => self::dateRules(true),
            'end_date' => [...self::dateRules(true), 'after_or_equal:start_date'],
            'half_day' => ['boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        self::assertSpan($data['start_date'], $data['end_date']);
        $leave = $this->workflow->amendAndApprove($leaveRequest, $request->user(), $data, $data['note'] ?? null);

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Leave amended and approved.']);
    }

    public function recall(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $data = $request->validate([
            'recall_date' => ['nullable', 'date'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $leave = $this->workflow->recall($leaveRequest, $request->user(), $data['recall_date'] ?? null, $data['reason'] ?? null);

        return response()->json(['data' => $leave->load(LeaveRequest::PRESENT_WITH)->present(), 'message' => 'Employee recalled from leave.']);
    }

    // ---- HR views ----

    /** All requests (HR). */
    public function all(Request $request): JsonResponse
    {
        $query = LeaveRequest::with(LeaveRequest::PRESENT_WITH)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('leave_type_id'), fn ($q) => $q->where('leave_type_id', $request->integer('leave_type_id')))
            ->when($request->filled('year'), fn ($q) => $q->whereYear('start_date', $request->integer('year')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('end_date', '>=', $request->date('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('start_date', '<=', $request->date('to')))
            ->when($request->filled('department_id') || $request->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e
                ->when($request->filled('department_id'), fn ($x) => $x->where('department_id', $request->integer('department_id')))
                ->when($request->filled('search'), fn ($x) => $x->search($request->query('search')))))
            ->orderByDesc('start_date')->orderByDesc('id');

        return response()->json($query->paginate(min($request->integer('per_page', 25), 100))->through(fn ($r) => $r->present()));
    }

    /** Staff on leave today. */
    public function onLeaveToday(Request $request): JsonResponse
    {
        $today = today()->toDateString();
        $requests = LeaveRequest::where('status', LeaveRequest::APPROVED)
            ->where('start_date', '<=', $today)->where('end_date', '>=', $today)
            ->when(($ids = LeaveAdminController::visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->with(['employee:id,first_name,last_name,photo_path,branch_id,department_id', 'employee.branch:id,name', 'employee.department:id,name', 'leaveType:id,name,color'])
            ->orderBy('start_date')->get()->map->present();

        return response()->json(['data' => $requests]);
    }

    /** Leave planner: approved/pending requests for a date range. */
    public function planner(Request $request): JsonResponse
    {
        $from = $request->input('from', today()->startOfMonth()->toDateString());
        $to = $request->input('to', today()->endOfMonth()->toDateString());

        $requests = LeaveRequest::whereIn('status', [LeaveRequest::APPROVED, LeaveRequest::PENDING_SUPERVISOR, LeaveRequest::PENDING_HANDOVER])
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->when(($ids = LeaveAdminController::visibleEmployeeIds($request->user())) !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->with(['employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name', 'leaveType:id,name,color'])
            ->orderBy('start_date')->get()->map->present();

        return response()->json(['data' => $requests]);
    }

    // ---- Balance management (HR) ----

    public function allBalances(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: now()->year;
        // Never show "No balances yet": anyone without a row for this (current or past) year gets their opening
        // balances now, exactly as their own My Leave page would create them.
        if ($year <= now()->year) {
            Employee::where('status', 'active')->whereDoesntHave('leaveBalances', fn ($q) => $q->where('year', $year))
                ->get()->each(fn (Employee $e) => app(\App\Modules\Leave\Services\LeaveBalanceService::class)->summary($e, $year));
        }
        $employees = Employee::with(['department:id,name', 'leaveBalances' => fn ($q) => $q->where('year', $year)->with('leaveType:id,name,code,color,sort_order')])
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('search'), fn ($q) => $q->search($request->query('search')))
            ->where('status', 'active')->orderBy('first_name')->orderBy('last_name')->get();

        return response()->json(['year' => $year, 'data' => $employees->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->full_name,
            'employee_number' => $e->employee_number,
            'department' => $e->department?->name,
            'gender' => $e->gender,
            'photo_url' => $e->photo_url,
            'balances' => $e->leaveBalances->sortBy(fn ($b) => $b->leaveType?->sort_order)->values()->map(fn ($b) => [
                'id' => $b->id,
                'leave_type' => ['id' => $b->leaveType->id, 'name' => $b->leaveType->name, 'code' => $b->leaveType->code, 'color' => $b->leaveType->color],
                'entitled' => $b->entitled,
                'accrued' => $b->accrued,
                'taken' => $b->taken,
                'adjusted' => $b->adjusted,
                'carried_forward' => $b->carried_forward,
                'forfeited' => $b->forfeited,
                'available' => $b->available,
            ]),
        ])]);
    }

    public function adjustBalance(Request $request, int $balanceId): JsonResponse
    {
        $balance = \App\Modules\Leave\Models\LeaveBalance::findOrFail($balanceId);
        $data = $request->validate([
            'days' => ['required', 'numeric'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        abort_if((float) $data['days'] == 0.0, 422, 'Enter a non-zero number of days.');
        $this->balances->post($balance, 'adjustment', (float) $data['days'], $data['reason'], $request->user()->id);
        activity('leave')->performedOn($balance)->causedBy($request->user())->withProperties($data)->log('Leave balance adjusted');

        return response()->json(['data' => $balance->refresh(), 'message' => 'Balance adjusted.']);
    }

    // ---- Helpers ----

    private function myEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'Your account is not linked to an employee record.');

        return $employee;
    }

    private function validated(Request $request): array
    {
        // Multipart forms send booleans as "true"/"false".
        $request->merge(['half_day' => $request->boolean('half_day')]);

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'start_date' => self::dateRules(true),
            'end_date' => [...self::dateRules(true), 'after_or_equal:start_date'],
            'half_day' => ['boolean'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'handover1_id' => ['nullable', 'integer', 'exists:employees,id'],
            'handover2_id' => ['nullable', 'integer', 'exists:employees,id'],
            'duties' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:5120'],
        ]);
        self::assertSpan($data['start_date'], $data['end_date']);

        return $data;
    }

    /**
     * Leave dates must be real calendar dates within a sensible window. Without this, a half-typed year in the
     * date picker (e.g. 0202-11-04) made the live preview count ~465,000 days and hold the request for minutes.
     */
    public static function dateRules(bool $required): array
    {
        return [$required ? 'required' : 'nullable', 'date_format:Y-m-d',
            'after_or_equal:'.now()->subYear()->startOfYear()->toDateString(),
            'before_or_equal:'.now()->addYears(2)->endOfYear()->toDateString()];
    }

    /** One request covers at most a year (maternity, the longest type, is 90 calendar days). */
    public static function assertSpan(string|\DateTimeInterface $start, string|\DateTimeInterface $end): void
    {
        if (\Carbon\Carbon::parse($start)->diffInDays(\Carbon\Carbon::parse($end)) > 366) {
            throw \Illuminate\Validation\ValidationException::withMessages(['end_date' => 'A single leave request cannot span more than a year.']);
        }
    }
}
