<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceShift;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shift schedules (working hours, break, grace, working days) and their employee assignments. */
class ShiftController extends Controller
{
    public function index(): JsonResponse
    {
        $shifts = AttendanceShift::withCount('employees')->orderByDesc('is_default')->orderBy('name')->get();

        return response()->json(['data' => $shifts->map(fn ($s) => $this->present($s))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $shift = new AttendanceShift($data);
        $shift->company_id = Tenancy::companyIdForWrite($request->integer('company_id') ?: null)
            ?? throw ValidationException::withMessages(['company_id' => 'Select a company first.']);
        DB::transaction(function () use ($shift) {
            $shift->save();
            $this->ensureSingleDefault($shift);
        });

        return response()->json(['message' => 'Shift created.', 'data' => $this->present($shift->loadCount('employees'))], 201);
    }

    public function update(Request $request, AttendanceShift $shift): JsonResponse
    {
        $shift->fill($this->validated($request));
        DB::transaction(function () use ($shift) {
            $shift->save();
            $this->ensureSingleDefault($shift);
        });

        return response()->json(['message' => 'Shift updated.', 'data' => $this->present($shift->loadCount('employees'))]);
    }

    public function destroy(AttendanceShift $shift): JsonResponse
    {
        $shift->delete();

        return response()->json(['message' => 'Shift deleted. Its employees fall back to the company working hours.']);
    }

    public function employees(AttendanceShift $shift): JsonResponse
    {
        $employees = $shift->employees()->with('department:id,name')->orderBy('first_name')
            ->get(['employees.id', 'employees.first_name', 'employees.last_name', 'employees.employee_number', 'employees.photo_path', 'employees.department_id']);

        return response()->json(['data' => $employees->map(fn ($e) => [
            'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number, 'photo_url' => $e->photo_url, 'department' => $e->department?->name,
        ])->values()]);
    }

    /** Assigns employees to this shift (moving them from any other shift). */
    public function assign(Request $request, AttendanceShift $shift): JsonResponse
    {
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:1000'], 'employee_ids.*' => ['integer']]);
        $ids = Employee::where('company_id', $shift->company_id)->whereIn('id', $data['employee_ids'])->pluck('id');
        DB::transaction(function () use ($ids, $shift) {
            foreach ($ids as $id) {
                DB::table('attendance_employee_shifts')->updateOrInsert(['employee_id' => $id], ['shift_id' => $shift->id, 'updated_at' => now(), 'created_at' => now()]);
            }
        });
        activity('attendance')->performedOn($shift)->causedBy($request->user())->withProperties(['employee_ids' => $ids])->log('Employees assigned to shift');

        return response()->json(['message' => $ids->count().' employee(s) assigned to '.$shift->name.'.', 'employee_count' => $shift->employees()->count()]);
    }

    public function unassign(Request $request, AttendanceShift $shift): JsonResponse
    {
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1'], 'employee_ids.*' => ['integer']]);
        $removed = DB::table('attendance_employee_shifts')->where('shift_id', $shift->id)->whereIn('employee_id', $data['employee_ids'])->delete();

        return response()->json(['message' => $removed.' employee(s) removed from '.$shift->name.'.', 'employee_count' => $shift->employees()->count()]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'break_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'grace_minutes' => ['nullable', 'integer', 'min:0', 'max:120'],
            'days' => ['nullable', 'string', 'max:60'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer', 'between:1,7'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ], ['end_time.after' => 'Overnight shifts are not supported yet: the end time must be after the start time.']);

        $days = AttendanceShift::parseDays($data['working_days'] ?? $data['days'] ?? 'Mon-Fri');
        if (! $days) {
            throw ValidationException::withMessages(['days' => 'Enter working days such as "Mon-Fri" or "Mon, Wed, Fri".']);
        }

        return [
            'name' => $data['name'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'break_minutes' => (int) ($data['break_minutes'] ?? 0),
            'grace_minutes' => isset($data['grace_minutes']) && $data['grace_minutes'] !== '' ? (int) $data['grace_minutes'] : null,
            'working_days' => $days,
            'is_default' => (bool) ($data['is_default'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function ensureSingleDefault(AttendanceShift $shift): void
    {
        if ($shift->is_default) {
            AttendanceShift::withoutGlobalScope('company')->where('company_id', $shift->company_id)->where('id', '!=', $shift->id)->update(['is_default' => false]);
        }
    }

    private function present(AttendanceShift $s): array
    {
        return [
            'id' => $s->id,
            'company_id' => $s->company_id,
            'name' => $s->name,
            'start_time' => $s->start_time,
            'end_time' => $s->end_time,
            'break_minutes' => $s->break_minutes,
            'grace_minutes' => $s->grace_minutes,
            'working_days' => $s->working_days,
            'days' => $s->daysLabel(),
            'is_default' => $s->is_default,
            'is_active' => $s->is_active,
            'employee_count' => $s->employees_count ?? 0,
        ];
    }
}
