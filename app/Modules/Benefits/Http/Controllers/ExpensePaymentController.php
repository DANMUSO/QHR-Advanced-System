<?php

namespace App\Modules\Benefits\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Benefits\Models\ExpenseClaim;
use App\Modules\Benefits\Services\ExpenseWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Finance (claims.pay) payment step for approved expense claims: pay directly (M-Pesa / bank / cash, with a
 * reference) or through payroll, where the amount is added to the next run as a non-taxable reimbursement
 * (ExpenseReimbursementInputs) and marked paid when that run is approved.
 */
class ExpensePaymentController extends Controller
{
    public function __construct(private ExpenseWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status', 'approved');
        abort_unless(in_array($status, ['approved', 'payroll_pending', 'paid'], true), 422);

        $query = ExpenseClaim::with('employee:id,first_name,last_name,photo_path,department_id,phone', 'employee.department:id,name')
            ->where('status', $status)
            ->when($request->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->search($request->query('search'))));

        $rows = (clone $query)->orderBy($status === 'paid' ? 'paid_at' : 'decided_at', $status === 'paid' ? 'desc' : 'asc')
            ->limit(500)->get();

        return response()->json([
            'data' => $rows->map(fn (ExpenseClaim $e) => ExpenseController::presentExpense($e, true) + ['phone' => $e->employee?->phone]),
            'totals' => ['count' => $rows->count(), 'amount' => round((float) $rows->sum('amount'), 2)],
            'methods' => ExpenseClaim::PAYMENT_METHODS,
        ]);
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(array_keys(ExpenseClaim::PAYMENT_METHODS))],
            'payment_reference' => ['nullable', 'required_if:payment_method,mpesa,bank', 'string', 'max:100'],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
        ], ['payment_reference.required_if' => 'Enter the M-Pesa or bank transaction reference.']);
        $user = $request->user();

        $claim = DB::transaction(function () use ($id, $data, $user) {
            $claim = ExpenseClaim::with('employee')->lockForUpdate()->findOrFail($id);
            abort_if($claim->employee?->user_id === $user->id, 403, 'You cannot pay your own expense claim.');
            if ($claim->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Only approved expense claims awaiting payment can be paid.']);
            }

            if ($data['payment_method'] === 'payroll') {
                $claim->update(['status' => 'payroll_pending', 'payment_method' => 'payroll', 'payroll_queued_at' => now(), 'paid_by' => $user->id]);
            } else {
                $claim->update([
                    'status' => 'paid', 'payment_method' => $data['payment_method'], 'payment_reference' => $data['payment_reference'] ?? null,
                    'paid_at' => isset($data['paid_on']) ? \Illuminate\Support\Carbon::parse($data['paid_on'])->setTimeFrom(now()) : now(), 'paid_by' => $user->id,
                ]);
            }

            return $claim;
        });

        activity('expenses')->performedOn($claim)->causedBy($user)
            ->withProperties(['method' => $claim->payment_method, 'reference' => $claim->payment_reference, 'amount' => (float) $claim->amount])
            ->log($claim->status === 'paid' ? 'Expense claim paid' : 'Expense claim queued for payroll');
        $this->workflow->notifyEmployee($claim, $claim->status);
        if ($claim->status === 'payroll_pending' && class_exists(\App\Modules\Payroll\Services\PayrollRunService::class)) {
            // An already-calculated run must be recalculated to include the reimbursement.
            \App\Modules\Payroll\Services\PayrollRunService::inputsChanged((int) $claim->company_id, [(int) $claim->employee_id]);
        }

        return response()->json([
            'data' => ExpenseController::presentExpense($claim->fresh('employee'), true),
            'message' => $claim->status === 'paid' ? 'Expense claim marked as paid.' : 'Expense claim will be paid through the next payroll run.',
        ]);
    }
}
