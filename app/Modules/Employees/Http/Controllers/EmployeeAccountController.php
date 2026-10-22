<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Login accounts: create, resend activation (single and bulk). */
class EmployeeAccountController extends Controller
{
    public function __construct(private AccountService $accounts) {}

    public function create(int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        abort_unless($e->status === 'active', 422, 'Logins can only be created for active employees.');
        DB::transaction(fn () => $this->accounts->createLogin($e));

        return response()->json(['message' => "Login created and activation email sent to {$e->email}."]);
    }

    public function resend(int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        $user = $e->user()->first();
        abort_unless($user, 422, "{$e->full_name} has no login yet. Create one first.");
        abort_if($user->activated_at !== null, 422, 'This account is already activated.');
        abort_unless($user->is_active, 422, 'This login is disabled.');
        $this->accounts->sendActivation($user);

        return response()->json(['message' => "Activation email re-sent to {$user->email}."]);
    }

    public function bulkCreate(Request $request): JsonResponse
    {
        $ids = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:500'], 'employee_ids.*' => ['integer']])['employee_ids'];
        $done = [];
        $failed = [];
        foreach (Employee::whereIn('id', $ids)->get() as $e) {
            if ($e->status !== 'active') {
                $failed[] = ['id' => $e->id, 'name' => $e->full_name, 'error' => 'Not an active employee.'];
                continue;
            }
            try {
                DB::transaction(fn () => $this->accounts->createLogin($e));
                $done[] = $e->id;
            } catch (ValidationException $ex) {
                $failed[] = ['id' => $e->id, 'name' => $e->full_name, 'error' => collect($ex->errors())->flatten()->first()];
            }
        }

        return response()->json([
            'created' => count($done), 'failed' => $failed,
            'message' => count($done).' login(s) created'.($failed ? ', '.count($failed).' skipped.' : '.'),
        ]);
    }

    public function bulkResend(Request $request): JsonResponse
    {
        $ids = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:500'], 'employee_ids.*' => ['integer']])['employee_ids'];
        $sent = 0;
        foreach (Employee::whereIn('id', $ids)->with('user')->get() as $e) {
            if ($e->user && $e->user->is_active && $e->user->activated_at === null) {
                $this->accounts->sendActivation($e->user);
                $sent++;
            }
        }

        return response()->json(['sent' => $sent, 'message' => "{$sent} activation email(s) sent."]);
    }
}
