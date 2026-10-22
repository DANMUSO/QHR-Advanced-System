<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Services\Calculation\LoanSchedule;
use App\Modules\Payroll\Services\LoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanController extends PayrollController
{
    public function __construct(private LoanService $loans) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));
        $page = PayrollLoan::with(['employee:id,first_name,last_name,employee_number', 'schedule'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('reference', 'like', "%{$search}%")->orWhereHas('employee', fn ($e) => $e->search($search))))
            ->orderByRaw("CASE status WHEN 'active' THEN 0 WHEN 'paused' THEN 1 WHEN 'completed' THEN 2 ELSE 3 END")->latest()
            ->paginate(min($request->integer('per_page', 25) ?: 25, 200));

        $all = PayrollLoan::whereIn('status', ['active', 'paused']);

        return response()->json(array_merge($page->through(fn ($l) => $this->loans->present($l))->toArray(), [
            'summary' => [
                'active' => (clone $all)->where('status', 'active')->count(),
                'paused' => (clone $all)->where('status', 'paused')->count(),
                'outstanding' => round((float) (clone $all)->sum('balance'), 2),
                'pending_advances' => PayrollSalaryAdvance::where('status', 'pending')->count(),
            ],
        ]));
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validateLoan($request, false);

        return response()->json(['data' => LoanSchedule::build((float) $data['principal'], $data['interest_method'], (float) ($data['interest_rate'] ?? 0), (int) $data['instalments'], $data['start_period'].'-01')]);
    }

    private function validateLoan(Request $request, bool $withEmployee = true): array
    {
        return $request->validate(array_filter([
            'employee_id' => $withEmployee ? ['required', 'integer'] : null,
            'principal' => ['required', 'numeric', 'min:100', 'max:100000000'],
            'interest_method' => ['required', Rule::in(['flat', 'reducing'])],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'instalments' => ['required', 'integer', 'min:1', 'max:120'],
            'start_period' => ['required', 'date_format:Y-m'],
            'description' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateLoan($request);
        $employee = Employee::findOrFail($data['employee_id']);
        $loan = $this->loans->create($employee, $data, $request->user());

        return response()->json(['data' => $this->loans->present($loan->load(['employee', 'schedule']), true), 'message' => "Loan {$loan->reference} created."], 201);
    }

    public function show(PayrollLoan $loan): JsonResponse
    {
        return response()->json(['data' => $this->loans->present($loan->load(['employee', 'schedule']), true)]);
    }

    public function pause(Request $request, PayrollLoan $loan): JsonResponse
    {
        $this->loans->setStatus($loan, 'paused', $request->user(), $request->input('reason'));

        return response()->json(['message' => 'Loan deductions paused.', 'data' => $this->loans->present($loan->fresh(['employee', 'schedule']), true)]);
    }

    public function resume(Request $request, PayrollLoan $loan): JsonResponse
    {
        $this->loans->setStatus($loan, 'active', $request->user());

        return response()->json(['message' => 'Loan deductions resumed.', 'data' => $this->loans->present($loan->fresh(['employee', 'schedule']), true)]);
    }

    public function settle(Request $request, PayrollLoan $loan): JsonResponse
    {
        $data = $request->validate(['amount' => ['nullable', 'numeric', 'min:0'], 'reason' => ['nullable', 'string', 'max:500']]);
        $loan = $this->loans->settle($loan, $request->user(), isset($data['amount']) ? (float) $data['amount'] : null, $data['reason'] ?? null);

        return response()->json(['message' => 'Loan settled.', 'data' => $this->loans->present($loan->load(['employee', 'schedule']), true)]);
    }

    // ---------------------------------------------------------------- salary advances (management)

    public function advances(Request $request): JsonResponse
    {
        $page = PayrollSalaryAdvance::with(['employee:id,first_name,last_name,employee_number', 'decider:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'recovered' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END")->latest()
            ->paginate(min($request->integer('per_page', 25) ?: 25, 200));

        return response()->json($page->through(fn ($a) => $this->loans->presentAdvance($a)));
    }

    public function approveAdvance(Request $request, PayrollSalaryAdvance $advance): JsonResponse
    {
        $data = $request->validate([
            'recovery_period' => ['nullable', 'date_format:Y-m'],
            'repayment_months' => ['nullable', 'integer', 'min:1', 'max:'.LoanService::MAX_ADVANCE_MONTHS],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $advance = $this->loans->approveAdvance($advance, $request->user(), $data['recovery_period'] ?? null,
            isset($data['repayment_months']) ? (int) $data['repayment_months'] : null, $data['note'] ?? null);
        $months = max(1, (int) $advance->repayment_months);

        return response()->json([
            'message' => 'Salary advance approved. Recovery starts in '.$advance->recovery_period?->format('F Y').($months > 1 ? " over {$months} months." : '.'),
            'data' => $this->loans->presentAdvance($advance),
        ]);
    }

    public function rejectAdvance(Request $request, PayrollSalaryAdvance $advance): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $advance = $this->loans->rejectAdvance($advance, $request->user(), $data['reason']);

        return response()->json(['message' => 'Salary advance rejected.', 'data' => $this->loans->presentAdvance($advance)]);
    }

    // ---------------------------------------------------------------- self-service

    public function myLoans(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $loans = PayrollLoan::withoutGlobalScopes()->with(['employee', 'schedule'])->where('employee_id', $me->id)->latest()->get();

        return response()->json(['data' => $loans->map(fn ($l) => $this->loans->present($l, true))]);
    }

    public function myAdvances(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $limit = $this->loans->advanceLimit($me);

        return response()->json([
            'data' => PayrollSalaryAdvance::withoutGlobalScopes()->where('employee_id', $me->id)->latest()->get()->map(fn ($a) => $this->loans->presentAdvance($a)),
            'max_amount' => $limit['max'],
            'max_percent' => $limit['percent'],
            'max_months' => LoanService::MAX_ADVANCE_MONTHS,
            'eligible' => (bool) $limit['profile'],
        ]);
    }

    public function requestAdvance(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:500'],
            'reason' => ['required', 'string', 'max:500'],
            'repayment_months' => ['nullable', 'integer', 'min:1', 'max:'.LoanService::MAX_ADVANCE_MONTHS],
        ]);
        $advance = $this->loans->requestAdvance($me, (float) $data['amount'], $data['reason'], (int) ($data['repayment_months'] ?? 1), $request->user());

        return response()->json(['message' => 'Salary advance requested. You will be notified once it is reviewed.', 'data' => $this->loans->presentAdvance($advance)], 201);
    }

    public function cancelAdvance(Request $request, PayrollSalaryAdvance $advance): JsonResponse
    {
        abort_unless($advance->employee_id === $this->me($request)->id, 403);
        abort_unless($advance->status === 'pending', 422, 'Only a pending request can be cancelled.');
        $advance->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Request cancelled.']);
    }
}
