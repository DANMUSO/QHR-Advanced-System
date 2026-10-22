<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Notifications\AttendanceDecisionNotification;
use App\Modules\Attendance\Support\AttendanceAccess;
use App\Modules\Attendance\Support\Presenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Overtime claims generated from attendance; approved hours feed payroll. */
class OvertimeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'paid'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'employee_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $query = $this->scoped($request)
            ->with(['employee:id,first_name,last_name,employee_number,photo_path,branch_id,department_id,primary_supervisor_id,secondary_supervisor_id', 'employee.department:id,name', 'decider:id,name'])
            ->when($f['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($f['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($f['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->whereHas('employee', fn ($e) => $e
                ->when($f['branch_id'] ?? null, fn ($x, $b) => $x->where('branch_id', $b))
                ->when($f['department_id'] ?? null, fn ($x, $d) => $x->where('department_id', $d))
                ->search($f['search'] ?? null));

        $counts = (clone $query)->reorder()->toBase()->selectRaw('status, COUNT(*) as c, SUM(minutes) as m')->groupBy('status')->get()->keyBy('status');
        $page = $query->when($f['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status = 'pending' desc")->orderByDesc('date')
            ->paginate($f['per_page'] ?? 25)
            ->through(fn ($o) => $this->present($o, $request));

        return response()->json(array_merge($page->toArray(), [
            'summary' => collect(['pending', 'approved', 'rejected', 'paid'])->mapWithKeys(fn ($s) => [$s => [
                'count' => (int) ($counts[$s]->c ?? 0), 'hours' => round(($counts[$s]->m ?? 0) / 60, 1),
            ]]),
        ]));
    }

    public function approve(Request $request, AttendanceOvertime $overtime): JsonResponse
    {
        return $this->decide($request, $overtime, 'approved');
    }

    public function reject(Request $request, AttendanceOvertime $overtime): JsonResponse
    {
        return $this->decide($request, $overtime, 'rejected');
    }

    private function decide(Request $request, AttendanceOvertime $overtime, string $status): JsonResponse
    {
        $data = $request->validate(['reason' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'max:500'], 'minutes' => ['nullable', 'integer', 'min:1', 'max:1440']]);
        abort_unless(AttendanceAccess::canDecide($request->user(), $overtime->employee), 403, 'You cannot decide on this overtime claim.');
        abort_unless($overtime->status === 'pending', 422, 'This claim has already been '.$overtime->status.'.');

        $changes = ['status' => $status, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'reason' => $data['reason'] ?? null];
        if ($status === 'approved' && ! empty($data['minutes']) && $data['minutes'] < $overtime->minutes) {
            // Approve fewer hours than claimed ("amended").
            $changes['minutes'] = $data['minutes'];
            $changes['amount'] = $overtime->amount !== null ? round($overtime->amount * $data['minutes'] / $overtime->minutes, 2) : null;
        }
        $overtime->forceFill($changes)->save();

        activity('attendance')->performedOn($overtime)->causedBy($request->user())
            ->withProperties(['employee_id' => $overtime->employee_id, 'date' => $overtime->date->toDateString(), 'minutes' => $overtime->minutes, 'reason' => $data['reason'] ?? null])
            ->log('Overtime '.$status);
        Presenter::notify($overtime->employee, new AttendanceDecisionNotification('overtime', $status,
            $overtime->hours.' h overtime on '.$overtime->date->format('D j M Y'), $data['reason'] ?? null, $request->user()->name));

        return response()->json(['message' => 'Overtime '.$status.'.', 'data' => $this->present($overtime->load('employee', 'decider:id,name'), $request)]);
    }

    private function scoped(Request $request): Builder
    {
        $q = AttendanceOvertime::query();
        if (! AttendanceAccess::managesAll($request->user())) {
            $q->whereIn('employee_id', AttendanceAccess::teamQuery($request->user())->pluck('id'));
        }

        return $q;
    }

    private function present(AttendanceOvertime $o, Request $request): array
    {
        return [
            'id' => $o->id,
            'employee_id' => $o->employee_id,
            'employee_name' => $o->employee?->full_name,
            'employee' => Presenter::employee($o->employee),
            'date' => $o->date->toDateString(),
            'day_type' => $o->day_type,
            'minutes' => $o->minutes,
            'hours' => $o->hours,
            'rate' => rtrim(rtrim(number_format($o->rate, 2), '0'), '.').'×',
            'multiplier' => $o->rate,
            'amount' => $o->amount,
            'status' => $o->status,
            'reason' => $o->reason,
            'decided_by' => $o->decider?->name,
            'decided_at' => $o->decided_at,
            'payroll_run_id' => $o->payroll_run_id,
            'paid_at' => $o->paid_at,
            'can_decide' => $o->status === 'pending' && $o->employee && AttendanceAccess::canDecide($request->user(), $o->employee),
        ];
    }
}
