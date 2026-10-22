<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\ExitRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Exit clearance board: departments sign off each clearance item for staff who are leaving
 * (scheduled exits, or completed exits whose clearance is still open).
 */
class ExitClearanceController extends Controller
{
    /** Department responsible for each ExitRecord::CLEARANCE_ITEMS key. */
    public const DEPARTMENTS = [
        'handover' => 'Line manager',
        'assets' => 'Administration',
        'it_access' => 'ICT',
        'finance' => 'Finance',
        'hr_documents' => 'Human Resources',
        'exit_interview' => 'Human Resources',
    ];

    /** GET /compliance/exits */
    public function index(Request $request): JsonResponse
    {
        $exits = ExitRecord::query()
            // Employee::query() carries the company scope.
            ->whereIn('employee_id', Employee::query()->select('employees.id'))
            ->where(fn ($q) => $q->where('status', 'scheduled')
                ->orWhere(fn ($w) => $w->where('status', 'completed')->where(fn ($c) => $c->whereNull('clearance_status')->orWhere('clearance_status', '!=', 'cleared'))))
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope('company')->with(['position:id,title', 'department:id,name'])])
            ->orderBy('exit_date')
            ->get();

        return response()->json(['data' => $exits->map(fn (ExitRecord $x) => $this->present($x))->values()]);
    }

    /** POST /compliance/exits/{employee}/clearance/{item} — sign off one clearance item. */
    public function signOff(Request $request, int $employee, string $item): JsonResponse
    {
        abort_unless(array_key_exists($item, ExitRecord::CLEARANCE_ITEMS), 404, 'Unknown clearance item.');
        $e = Employee::findOrFail($employee); // company scope check
        $exit = ExitRecord::where('employee_id', $e->id)->whereIn('status', ['scheduled', 'completed'])->latest('id')->first();
        abort_unless($exit, 404, "{$e->full_name} has no exit in progress.");

        $user = $request->user();
        $note = $request->validate(['override_note' => ['nullable', 'string', 'max:500']])['override_note'] ?? null;
        // Assets: only when every assigned asset is returned, or HR overrides with a note.
        $override = null;
        if ($item === 'assets') {
            $exit->assertAssetsClearable($note);
            $override = $exit->outstandingAssets()->isNotEmpty() ? $note : null;
        }
        DB::transaction(function () use ($exit, $item, $user, $e, $override) {
            $exit = ExitRecord::whereKey($exit->id)->lockForUpdate()->first();
            $clearance = array_merge(array_fill_keys(array_keys(ExitRecord::CLEARANCE_ITEMS), false), $exit->clearance ?? []);
            abort_if($clearance[$item], 422, 'This item is already signed off.');

            $clearance[$item] = true;
            $signoffs = (array) ($exit->clearance_signoffs ?? []);
            $signoffs[$item] = ['user_id' => $user->id, 'name' => $user->name, 'at' => now()->toIso8601String()]
                + ($override ? ['override_note' => $override] : []);
            $done = count(array_filter($clearance));

            $exit->forceFill([
                'clearance' => $clearance,
                'clearance_signoffs' => $signoffs,
                'clearance_status' => $done === count(ExitRecord::CLEARANCE_ITEMS) ? 'cleared' : 'in_progress',
            ])->save();

            activity('employees')->performedOn($e)->causedBy($user)
                ->withProperties(['exit_id' => $exit->id, 'item' => $item, 'clearance_status' => $exit->clearance_status])
                ->log('Exit clearance signed off: '.ExitRecord::CLEARANCE_ITEMS[$item]);
        });

        return response()->json(['message' => 'Clearance item signed off.', 'data' => $this->present($exit->fresh('employee'))]);
    }

    private function present(ExitRecord $x): array
    {
        $e = $x->employee;
        $clearance = $x->clearance ?? [];
        $signoffs = (array) ($x->clearance_signoffs ?? []);

        return [
            'id' => $x->employee_id,
            'exit_id' => $x->id,
            'name' => $e?->full_name,
            'employee_number' => $e?->employee_number,
            'photo_url' => $e?->photo_url,
            'position' => $e?->position?->title,
            'department' => $e?->department?->name,
            'last_working_day' => $x->exit_date?->toDateString(),
            'exit_status' => $x->status,
            'clearance_status' => $x->clearance_status,
            'clearance_complete' => $x->clearance_status === 'cleared',
            'clearance_items' => collect(ExitRecord::CLEARANCE_ITEMS)->map(fn ($label, $key) => [
                'id' => $key,
                'name' => $label,
                'department' => self::DEPARTMENTS[$key] ?? null,
                'cleared' => (bool) ($clearance[$key] ?? false),
                'cleared_by' => $signoffs[$key]['name'] ?? null,
                'cleared_at' => $signoffs[$key]['at'] ?? null,
                'override_note' => $signoffs[$key]['override_note'] ?? null,
            ])->values(),
            'outstanding_assets' => $x->assetsPayload(),
            'final_dues_url' => '/payroll?final_dues='.$x->employee_id,
        ];
    }
}
