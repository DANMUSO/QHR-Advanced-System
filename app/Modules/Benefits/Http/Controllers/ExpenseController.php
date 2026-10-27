<?php

namespace App\Modules\Benefits\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Models\TravelRequest;
use App\Modules\Benefits\Services\ExpenseWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\Company;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff expenses, travel requests and salary advance requests (self-service), plus the approval queue.
 * Approvals go to the employee's supervisor when they have an active login; otherwise to users with
 * claims.approve / payroll.manage in the company.
 */
class ExpenseController extends Controller
{
    // ── Expense claims ───────────────────────────────────────────────────────────────────────

    public function mine(Request $request): JsonResponse
    {
        $employee = $this->me($request);

        return response()->json(['data' => ExpenseClaim::where('employee_id', $employee->id)->latest('expense_date')->get()
            ->map(fn (ExpenseClaim $e) => $this->presentExpense($e)),
            'categories' => ExpenseClaim::CATEGORIES,
            'receipt_threshold' => static::receiptThreshold($employee->company_id)]);
    }

    public function store(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        // A receipt is required above the company threshold (default 0: always required).
        $threshold = static::receiptThreshold($employee->company_id);
        $needsReceipt = (float) $request->input('amount', 0) > $threshold;
        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(ExpenseClaim::CATEGORIES)],
            'amount' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'expense_date' => ['required', 'date', 'before_or_equal:today', 'after:'.now()->subDays(90)->toDateString()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => [$needsReceipt ? 'required' : 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120'],
        ], [
            'expense_date.after' => 'Expenses older than 90 days cannot be claimed.',
            'receipt.required' => $threshold > 0 ? 'Attach the receipt (required for expenses above '.number_format($threshold).').' : 'Attach the receipt.',
            'receipt.max' => 'The receipt must be 5 MB or smaller.',
            'receipt.mimes' => 'The receipt must be a PDF, JPG or PNG file.',
            'receipt.mimetypes' => 'The receipt must be a PDF, JPG or PNG file.',
        ]);
        unset($data['receipt']);

        $claim = new ExpenseClaim($data + ['status' => 'submitted', 'approver_employee_id' => $this->workflow()->approverIdFor($employee)]);
        $claim->employee_id = $employee->id;
        $claim->company_id = $employee->company_id;
        $claim->save();
        if ($file = $request->file('receipt')) {
            $claim->forceFill([
                'receipt_path' => $file->store("expenses/{$claim->company_id}/{$claim->id}", 'local'),
                'receipt_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'receipt_mime' => $file->getMimeType() ?: 'application/octet-stream',
                'receipt_size' => $file->getSize(),
            ])->save();
        }
        $this->workflow()->notifySubmitted($claim);

        return response()->json(['data' => $this->presentExpense($claim), 'message' => 'Expense submitted for approval.'], 201);
    }

    public function approvals(Request $request): JsonResponse
    {
        // Pending items this user can decide, plus what they decided in the last 30 days.
        $user = $request->user();
        $pendingIds = ExpenseWorkflow::pendingQuery(ExpenseClaim::class, $user)->pluck('id');
        $query = ExpenseClaim::with('employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name')
            ->where(fn ($q) => $q->whereIn('id', $pendingIds)
                ->orWhere(fn ($w) => $w->where('decided_by', $user->id)->where('decided_at', '>=', now()->subDays(30))));

        return response()->json(['data' => $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")->latest('expense_date')->get()
            ->map(fn (ExpenseClaim $e) => $this->presentExpense($e, true) + ['can_decide' => $e->status === 'submitted'])]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'approved');
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, 'rejected');
    }

    private function decide(Request $request, int $id, string $status): JsonResponse
    {
        $request->validate(['reason' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'min:3', 'max:500']]);
        $claim = ExpenseClaim::with('employee.user')->findOrFail($id);
        $user = $request->user();

        abort_if($claim->employee_id === $user->employee?->id, 403, 'You cannot approve your own expense.');
        abort_unless($this->workflow()->canDecide($user, $claim), 403, 'You are not the approver for this expense.');
        if ($claim->status !== 'submitted') {
            throw ValidationException::withMessages(['status' => 'This expense has already been '.$claim->status.'.']);
        }

        $claim->update([
            'status' => $status, 'decided_by' => $user->id, 'decided_at' => now(),
            'decision_note' => $request->input('reason') ?: null,
        ]);
        activity('expenses')->performedOn($claim)->causedBy($user)->withProperties(['reason' => $claim->decision_note])->log("Expense claim {$status}");
        $this->workflow()->notifyEmployee($claim, $status);
        if ($status === 'approved') {
            $this->workflow()->notifyFinance($claim);
        }

        return response()->json(['data' => $this->presentExpense($claim->fresh(), true), 'message' => 'Expense '.$status.'.']);
    }

    // ── Travel requests ──────────────────────────────────────────────────────────────────────

    public function travel(Request $request): JsonResponse
    {
        $employee = $this->me($request);

        return response()->json(['data' => TravelRequest::where('employee_id', $employee->id)->latest('departure_date')->get()
            ->map(fn (TravelRequest $t) => [
                'id' => $t->id, 'destination' => $t->destination, 'purpose' => $t->purpose,
                'departure_date' => $t->departure_date?->toDateString(), 'return_date' => $t->return_date?->toDateString(),
                'estimated_cost' => (float) $t->estimated_cost, 'notes' => $t->notes, 'status' => $t->status,
                'decision_note' => $t->decision_note, 'created_at' => $t->created_at,
            ])]);
    }

    public function storeTravel(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        $data = $request->validate([
            'destination' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'max:255'],
            'departure_date' => ['required', 'date', 'after_or_equal:today'],
            'return_date' => ['required', 'date', 'after_or_equal:departure_date'],
            'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip = new TravelRequest($data + ['estimated_cost' => $data['estimated_cost'] ?? 0, 'status' => 'pending', 'approver_employee_id' => $this->workflow()->approverIdFor($employee)]);
        $trip->employee_id = $employee->id;
        $trip->company_id = $employee->company_id;
        $trip->save();
        $this->workflow()->notifySubmitted($trip);

        return response()->json(['data' => $trip, 'message' => 'Travel request submitted for approval.'], 201);
    }

    // ── Salary advances (stored in the Payroll module's table) ──────────────────────────────

    public function advances(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        abort_unless(Schema::hasTable('payroll_salary_advances'), 503, 'Payroll is not installed.');

        $rows = DB::table('payroll_salary_advances')->where('employee_id', $employee->id)->orderByDesc('created_at')->get()
            ->map(fn ($a) => [
                'id' => $a->id, 'amount' => (float) $a->amount, 'reason' => $a->reason,
                'requested_date' => substr((string) $a->created_at, 0, 10),
                'repayment_months' => $a->repayment_months ?? 1,
                'status' => $a->status === 'recovered' ? 'repaid' : $a->status,
                'decision_note' => $a->decision_note ?? null,
            ]);

        return response()->json(['data' => $rows]);
    }

    public function storeAdvance(Request $request): JsonResponse
    {
        $employee = $this->me($request);
        // Payroll owns salary advances: limits (advance_max_percent), recovery period, instalments and approver notifications.
        $loans = app(\App\Modules\Payroll\Services\LoanService::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:500'],
            'reason' => ['required', 'string', 'max:500'],
            'repayment_months' => ['required', 'integer', 'min:1', 'max:'.\App\Modules\Payroll\Services\LoanService::MAX_ADVANCE_MONTHS],
        ]);

        $advance = $loans->requestAdvance($employee, (float) $data['amount'], $data['reason'], (int) $data['repayment_months'], $request->user());
        activity('expenses')->causedBy($request->user())->withProperties(['amount' => $data['amount']])->log("Requested a salary advance (#{$advance->id})");

        return response()->json(['data' => ['id' => $advance->id] + $loans->presentAdvance($advance), 'message' => 'Salary advance requested. Payroll will review it.'], 201);
    }

    /**
     * Authorised receipt download (?inline=1 previews): the claimant, their approver, expense approvers
     * (claims.approve / payroll.manage) and finance (claims.pay).
     */
    public function receipt(Request $request, int $id): StreamedResponse
    {
        $claim = ExpenseClaim::findOrFail($id);
        $user = $request->user();
        $allowed = $claim->employee_id === $user->employee?->id
            || ($user->employee && (int) $claim->approver_employee_id === (int) $user->employee->id)
            || (int) $claim->decided_by === (int) $user->id
            || ExpenseWorkflow::canApproveAll($user)
            || $user->can('claims.pay');
        abort_unless($allowed, 403);
        abort_unless($claim->receipt_path && Storage::disk('local')->exists($claim->receipt_path), 404, 'No receipt was uploaded for this expense.');

        return Storage::disk('local')->response($claim->receipt_path, $claim->receipt_name ?: 'receipt', [
            'Content-Type' => $claim->receipt_mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ], $request->boolean('inline') ? 'inline' : 'attachment');
    }

    /** Amount above which an expense needs a receipt (company setting expense_receipt_threshold, default 0 = always). */
    public static function receiptThreshold(?int $companyId): float
    {
        $company = $companyId ? Company::find($companyId) : null;

        return max(0.0, (float) ($company?->setting('expense_receipt_threshold', 0) ?? 0));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────

    private function me(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'Your account is not linked to an employee record, so you cannot submit or view personal requests.');

        return $employee;
    }

    private function workflow(): ExpenseWorkflow
    {
        return app(ExpenseWorkflow::class);
    }

    public static function presentExpense(ExpenseClaim $e, bool $withEmployee = false): array
    {
        $row = [
            'id' => $e->id, 'description' => $e->description, 'category' => $e->category,
            'amount' => (float) $e->amount, 'expense_date' => $e->expense_date?->toDateString(),
            'notes' => $e->notes, 'status' => $e->status, 'decision_note' => $e->decision_note,
            'decided_at' => $e->decided_at, 'created_at' => $e->created_at,
            'payment_method' => $e->payment_method, 'payment_reference' => $e->payment_reference,
            'paid_at' => $e->paid_at, 'payroll_queued_at' => $e->payroll_queued_at,
            'has_receipt' => (bool) $e->receipt_path, 'receipt_name' => $e->receipt_name,
            'receipt_url' => $e->receipt_path ? '/api/expenses/'.$e->id.'/receipt' : null,
        ];
        if ($withEmployee) {
            $row += [
                'employee_id' => $e->employee_id,
                'employee_name' => $e->employee?->full_name,
                'photo' => $e->employee?->photo_url,
                'department' => $e->employee?->department?->name,
            ];
        }

        return $row;
    }
}
