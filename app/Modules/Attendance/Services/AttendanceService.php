<?php

namespace App\Modules\Attendance\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\AttendanceShift;
use App\Modules\Attendance\Support\AttendanceRules;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Builds attendance_days rows from punches, company rules, shifts, holidays and approved leave,
 * and keeps the overtime claims in step. All queries bypass the company scope and filter explicitly,
 * so the same code serves HTTP requests, the device API and the nightly command.
 */
class AttendanceService
{
    private array $companies = [];

    private array $holidays = [];

    private ?array $defaultShifts = null;

    private array $shiftById = [];

    private array $assignment = [];

    private array $basicSalary = [];

    public function __construct(private DayCalculator $calculator, private LeaveLookup $leave) {}

    // ---------------------------------------------------------------- rules

    public function company(int $id): ?Company
    {
        return $this->companies[$id] ??= Company::find($id);
    }

    /** Preloads shift assignments for many employees (avoids N+1 in bulk computations). */
    public function preloadShifts(array $employeeIds): void
    {
        $missing = array_diff($employeeIds, array_keys($this->assignment));
        if (! $missing) {
            return;
        }
        foreach (array_chunk($missing, 1000) as $chunk) {
            $rows = DB::table('attendance_employee_shifts')->whereIn('employee_id', $chunk)->pluck('shift_id', 'employee_id');
            foreach ($chunk as $id) {
                $this->assignment[$id] = $rows[$id] ?? null;
            }
        }
        $ids = array_filter(array_unique(array_values($this->assignment)));
        $need = array_diff($ids, array_keys($this->shiftById));
        if ($need) {
            foreach (AttendanceShift::withoutGlobalScope('company')->whereIn('id', $need)->get() as $s) {
                $this->shiftById[$s->id] = $s;
            }
        }
    }

    public function shiftFor(Employee $employee): ?AttendanceShift
    {
        $this->preloadShifts([$employee->id]);
        $shift = ($id = $this->assignment[$employee->id] ?? null) ? ($this->shiftById[$id] ?? null) : null;
        if ($shift && $shift->is_active) {
            return $shift;
        }
        $this->defaultShifts ??= AttendanceShift::withoutGlobalScope('company')->where('is_default', true)->where('is_active', true)
            ->get()->keyBy('company_id')->all();

        return $this->defaultShifts[$employee->company_id] ?? null;
    }

    public function rulesFor(Employee $employee): AttendanceRules
    {
        return AttendanceRules::forCompany($this->company($employee->company_id), $this->shiftFor($employee));
    }

    public function companyRules(int $companyId): AttendanceRules
    {
        return AttendanceRules::forCompany($this->company($companyId));
    }

    public function isHoliday(int $companyId, CarbonInterface $date): bool
    {
        $key = $companyId.'-'.$date->year;
        $this->holidays[$key] ??= array_flip(Holiday::datesBetween(
            CarbonImmutable::create($date->year, 1, 1), CarbonImmutable::create($date->year, 12, 31), $companyId
        )->all());

        return isset($this->holidays[$key][$date->toDateString()]);
    }

    public function dayType(Employee $employee, AttendanceRules $rules, CarbonInterface $date): string
    {
        return match (true) {
            $this->isHoliday($employee->company_id, $date) => 'holiday',
            ! $rules->isWorkingDay($date) => 'weekend',
            default => 'working',
        };
    }

    // ---------------------------------------------------------------- live evaluation

    /** Evaluates a day without saving it (today's views). */
    public function evaluate(Employee $employee, CarbonInterface $date, Collection $punches, ?bool $onLeave = null, ?bool $final = null, array|false|null $leaveWindow = null): array
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $rules = $this->rulesFor($employee);
        $type = $this->dayType($employee, $rules, $date);
        $onLeave ??= in_array($employee->id, $this->leave->onLeave($date, [$employee->id]), true);
        $final ??= $date->lt(today());
        // Hourly leave (null = look it up; false = caller already knows there is none).
        if ($leaveWindow === null) {
            $leaveWindow = $onLeave ? false : ($this->leave->partialLeave($date, [$employee->id])[$employee->id] ?? false);
        }

        $result = $this->calculator->calculate($date, $punches->map(fn ($p) => ['at' => $p->punched_at, 'direction' => $p->direction])->all(), $rules, $type, $onLeave, $final, $leaveWindow ?: null);
        $result['on_leave'] = $onLeave || $result['status'] === 'on_leave';
        $result['leave_window'] = $leaveWindow ?: null;
        $result['rules'] = $rules;

        return $result;
    }

    /**
     * Live status of many employees for a day: present | late | absent | on_leave | not_in | day_off.
     *
     * @return array<int, array>
     */
    public function liveStatuses(Collection $employees, CarbonInterface $date): array
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $ids = $employees->pluck('id')->all();
        if (! $ids) {
            return [];
        }
        $this->preloadShifts($ids);
        $punches = AttendancePunch::withoutGlobalScope('company')->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$date->startOfDay(), $date->endOfDay()])->orderBy('punched_at')->get()->groupBy('employee_id');
        $onLeave = array_flip($this->leave->onLeave($date, $ids));
        $partial = $this->leave->partialLeave($date, $ids);
        $now = now();

        $out = [];
        foreach ($employees as $e) {
            $r = $this->evaluate($e, $date, $punches[$e->id] ?? collect(), isset($onLeave[$e->id]), $date->lt(today()), $partial[$e->id] ?? false);
            $rules = $r['rules'];
            if ($r['on_leave'] && $r['day_type'] === 'working') {
                $status = 'on_leave'; // approved leave wins over stray punches on a working day
            } elseif ($r['punch_count'] > 0) {
                $status = $r['late_minutes'] > 0 && $r['day_type'] === 'working' ? 'late' : 'present';
            } elseif ($r['day_type'] !== 'working') {
                $status = 'day_off';
            } elseif ($date->lt(today()) || $now->gt($rules->startAt($date)->addMinutes($rules->graceMinutes))) {
                $status = 'absent';
            } else {
                $status = 'not_in';
            }
            $out[$e->id] = [
                'status' => $status,
                'first_in' => $r['first_in'],
                'last_out' => $r['last_out'],
                'late_minutes' => $r['late_minutes'],
                'worked_minutes' => $r['worked_minutes'],
                'day_type' => $r['day_type'],
                'punch_count' => $r['punch_count'],
            ];
        }

        return $out;
    }

    // ---------------------------------------------------------------- persistence

    /** Recomputes one employee-day (called after every punch or correction). */
    public function computeDay(Employee $employee, CarbonInterface $date): ?AttendanceDay
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $punches = AttendancePunch::withoutGlobalScope('company')->where('employee_id', $employee->id)
            ->whereBetween('punched_at', [$date->startOfDay(), $date->endOfDay()])->orderBy('punched_at')->get();

        $r = $this->evaluate($employee, $date, $punches);
        $r['employed'] = $this->employedOn($employee, $date);

        return $this->store($employee, $date, $r);
    }

    /**
     * Recomputes one employee's days in [from, to], never beyond today (e.g. after leave is
     * approved, cancelled or recalled). Returns the number of days processed.
     */
    public function recomputeEmployeeRange(int $employeeId, CarbonInterface $from, CarbonInterface $to): int
    {
        $employee = Employee::withoutGlobalScope('company')->find($employeeId);
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString())->min(CarbonImmutable::today());
        if (! $employee || $to->lt($from)) {
            return 0;
        }
        $n = 0;
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $this->computeDay($employee, $d);
            $n++;
        }

        return $n;
    }

    /**
     * Recomputes every relevant employee for one date, marking absentees on finished working days.
     *
     * @return array{date: string, saved: int, absent: int, removed: int}
     */
    public function computeDate(CarbonInterface $date, ?int $companyId = null): array
    {
        $date = CarbonImmutable::parse($date->toDateString());
        $final = $date->lt(today());

        $punches = AttendancePunch::withoutGlobalScope('company')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereBetween('punched_at', [$date->startOfDay(), $date->endOfDay()])->orderBy('punched_at')->get()->groupBy('employee_id');

        // Everyone employed that day (joined, not past their exit), plus anyone with punches or a stored row,
        // so a row that no longer belongs (e.g. after a backdated exit) is re-evaluated and removed.
        $rosterIds = $this->rosterQuery($date, $companyId)->pluck('id')->all();
        $storedIds = AttendanceDay::withoutGlobalScope('company')->whereDate('date', $date)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))->pluck('employee_id')->all();
        $employees = Employee::withoutGlobalScope('company')
            ->whereIn('id', array_values(array_unique(array_merge($rosterIds, $punches->keys()->all(), $storedIds))))
            ->get(['id', 'company_id', 'branch_id', 'first_name', 'last_name', 'status', 'date_of_joining']);
        $onRoster = array_flip($rosterIds);

        $ids = $employees->pluck('id')->all();
        $this->preloadShifts($ids);
        $onLeave = array_flip($this->leave->onLeave($date, $ids));
        $partial = $this->leave->partialLeave($date, $ids);
        $existing = AttendanceDay::withoutGlobalScope('company')->whereIn('employee_id', $ids)->whereDate('date', $date)->get()->keyBy('employee_id');

        $stats = ['date' => $date->toDateString(), 'saved' => 0, 'absent' => 0, 'removed' => 0];
        $tracking = [];
        foreach ($employees as $e) {
            $r = $this->evaluate($e, $date, $punches[$e->id] ?? collect(), isset($onLeave[$e->id]), $final, $partial[$e->id] ?? false);
            // Absentees only where the company keeps attendance at all (otherwise everyone would be "absent").
            $tracking[$e->company_id] ??= $this->tracksAttendance($e->company_id);
            $r['employed'] = isset($onRoster[$e->id]) && $tracking[$e->company_id];
            $day = $this->store($e, $date, $r, $existing[$e->id] ?? null);
            if ($day) {
                $stats['saved']++;
                $stats['absent'] += $day->status === 'absent' ? 1 : 0;
            } elseif (isset($existing[$e->id])) {
                $stats['removed']++;
            }
        }
        if ($final) {
            $this->markFinalised($date, $companyId ? [$companyId] : $employees->pluck('company_id')->unique()->all());
        }

        return $stats;
    }

    /**
     * Employees on the payroll that day: joined on or before it and not past an exit (exit date = last day worked).
     * Exited / transferred records count up to their exit date.
     */
    public function rosterQuery(CarbonInterface $date, ?int $companyId = null): \Illuminate\Database\Eloquent\Builder
    {
        $d = $date->toDateString();
        $hasExits = Schema::hasTable('employee_exits');

        return Employee::withoutGlobalScope('company')
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->where(fn ($j) => $j->whereNull('date_of_joining')->orWhereDate('date_of_joining', '<=', $d))
            ->when($hasExits, fn ($q) => $q
                ->whereNotExists(fn ($x) => $x->from('employee_exits')->whereColumn('employee_exits.employee_id', 'employees.id')
                    ->where('employee_exits.status', '!=', 'cancelled')->whereDate('employee_exits.exit_date', '<', $d))
                ->where(fn ($s) => $s->where('status', 'active')
                    ->orWhereExists(fn ($x) => $x->from('employee_exits')->whereColumn('employee_exits.employee_id', 'employees.id')
                        ->where('employee_exits.status', '!=', 'cancelled')->whereDate('employee_exits.exit_date', '>=', $d))),
                fn ($q) => $q->where('status', 'active'));
    }

    public function employedOn(Employee $employee, CarbonInterface $date): bool
    {
        return $this->tracksAttendance($employee->company_id) && $this->rosterQuery($date)->whereKey($employee->id)->exists();
    }

    private array $tracks = [];

    /** A company keeps attendance once anyone has clocked in (device, web or manual punch). */
    public function tracksAttendance(int $companyId): bool
    {
        if (! empty($this->tracks[$companyId])) {
            return true; // only "yes" is remembered: the first punch can arrive at any time
        }

        return $this->tracks[$companyId] = AttendancePunch::withoutGlobalScope('company')->withTrashed()->where('company_id', $companyId)->exists();
    }

    /** Memo of company-date pairs known to be finalised in this process. */
    private array $finalised = [];

    /**
     * Makes sure every finished day in [from, to] has been finalised (absentees written) for the companies,
     * computing any day the nightly job has not covered. At most 62 days are finalised per call.
     */
    public function ensureFinalised(CarbonInterface $from, CarbonInterface $to, array|int|null $companyIds = null): int
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString())->min(CarbonImmutable::yesterday());
        if ($to->lt($from) || ! Schema::hasTable('attendance_finalised_dates')) {
            return 0;
        }
        if ($from->diffInDays($to) > 62) {
            $from = $to->subDays(62);
        }
        $companyIds = $companyIds === null ? Company::query()->pluck('id')->all() : (array) $companyIds;
        $done = DB::table('attendance_finalised_dates')->whereIn('company_id', $companyIds)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get(['company_id', 'date'])
            ->mapWithKeys(fn ($r) => [$r->company_id.'|'.substr($r->date, 0, 10) => true])->all();

        $n = 0;
        foreach ($companyIds as $cid) {
            for ($d = $from; $d->lte($to); $d = $d->addDay()) {
                $key = $cid.'|'.$d->toDateString();
                if (isset($this->finalised[$key]) || isset($done[$key])) {
                    continue;
                }
                $this->computeDate($d, (int) $cid);
                $n++;
            }
        }

        return $n;
    }

    private function markFinalised(CarbonImmutable $date, array $companyIds): void
    {
        if (! Schema::hasTable('attendance_finalised_dates')) {
            return;
        }
        foreach (array_filter($companyIds) as $cid) {
            $this->finalised[$cid.'|'.$date->toDateString()] = true;
            DB::table('attendance_finalised_dates')->updateOrInsert(
                ['company_id' => $cid, 'date' => $date->toDateString()], ['computed_at' => now()]
            );
        }
    }

    /** Forget finalisation from a date on (roster or calendar changed), so the next read recomputes those days. */
    public function invalidateFinalised(int $companyId, CarbonInterface $from): void
    {
        $this->finalised = array_filter($this->finalised, fn ($v, $k) => ! str_starts_with($k, $companyId.'|') || substr($k, strlen($companyId) + 1) < $from->toDateString(), ARRAY_FILTER_USE_BOTH);
        if (Schema::hasTable('attendance_finalised_dates')) {
            DB::table('attendance_finalised_dates')->where('company_id', $companyId)->whereDate('date', '>=', $from->toDateString())->delete();
        }
    }

    public function computeRange(CarbonInterface $from, CarbonInterface $to, ?int $companyId = null): array
    {
        $totals = ['days' => 0, 'saved' => 0, 'absent' => 0, 'removed' => 0];
        for ($d = CarbonImmutable::parse($from->toDateString()); $d->lte($to); $d = $d->addDay()) {
            $s = $this->computeDate($d, $companyId);
            $totals['days']++;
            $totals['saved'] += $s['saved'];
            $totals['absent'] += $s['absent'];
            $totals['removed'] += $s['removed'];
        }

        return $totals;
    }

    /** Saves (or removes) the summary row. Rows exist for punches, leave, finished working days and overrides. */
    private function store(Employee $employee, CarbonImmutable $date, array $r, ?AttendanceDay $day = null): ?AttendanceDay
    {
        $day ??= AttendanceDay::withoutGlobalScope('company')->where('employee_id', $employee->id)->whereDate('date', $date)->first();
        $final = $date->lt(today());

        // Not employed that day (before joining, after exit): only punches or an HR override keep a row.
        $employed = $r['employed'] ?? true;
        $keep = $r['punch_count'] > 0
            || ($employed && $r['day_type'] === 'working' && ($r['on_leave'] || $final))
            || ($day && $day->override_status);

        if (! $keep) {
            if ($day) {
                AttendanceOvertime::withoutGlobalScope('company')->where('attendance_day_id', $day->id)->where('status', 'pending')->delete();
                $day->delete();
            }

            return null;
        }

        $day ??= new AttendanceDay(['employee_id' => $employee->id, 'date' => $date->toDateString()]);
        $day->forceFill([
            'company_id' => $employee->company_id,
            'day_type' => $r['day_type'],
            'first_in' => $r['first_in'],
            'last_out' => $r['last_out'],
            'punch_count' => $r['punch_count'],
            'worked_minutes' => $r['worked_minutes'],
            'regular_minutes' => $r['regular_minutes'],
            'late_minutes' => $r['late_minutes'],
            'early_leave_minutes' => $r['early_leave_minutes'],
            'overtime_minutes' => $r['overtime_minutes'],
            'computed_status' => $r['status'],
            'status' => $day->override_status ?: $r['status'],
            'computed_at' => now(),
        ])->save();

        $this->syncOvertime($employee, $day, $r['rules']);

        return $day;
    }

    /** Keeps one overtime claim per employee-day in step with the computed minutes (only while it is pending). */
    private function syncOvertime(Employee $employee, AttendanceDay $day, AttendanceRules $rules): void
    {
        $claim = AttendanceOvertime::withoutGlobalScope('company')->where('employee_id', $employee->id)->whereDate('date', $day->date)->first();
        $eligible = $day->last_out && $day->overtime_minutes >= max(1, $rules->overtimeMinMinutes) && $day->status !== 'incomplete';

        if (! $eligible) {
            if ($claim && $claim->status === 'pending') {
                $claim->delete();
            }

            return;
        }
        if ($claim && $claim->status !== 'pending') {
            return; // decided claims are not changed by recomputation
        }

        $rate = $day->day_type === 'working' ? $rules->overtimeRateNormal : $rules->overtimeRateHoliday;
        $basic = $this->basicSalary($employee->id);
        $claim ??= new AttendanceOvertime(['employee_id' => $employee->id, 'date' => $day->date->toDateString(), 'status' => 'pending']);
        $claim->forceFill([
            'company_id' => $employee->company_id,
            'attendance_day_id' => $day->id,
            'day_type' => $day->day_type,
            'minutes' => $day->overtime_minutes,
            'rate' => $rate,
            'amount' => $basic !== null ? round($basic / 225 * $rate * $day->overtime_minutes / 60, 2) : null,
        ])->save();
    }

    /** Monthly basic salary from the Payroll module, when installed (for overtime estimates). */
    public function basicSalary(int $employeeId): ?float
    {
        if (! array_key_exists($employeeId, $this->basicSalary)) {
            static $hasTable = null;
            $hasTable ??= Schema::hasTable('payroll_pay_profiles');
            $value = $hasTable ? DB::table('payroll_pay_profiles')->where('employee_id', $employeeId)->value('basic_salary') : null;
            $this->basicSalary[$employeeId] = $value !== null ? (float) $value : null;
        }

        return $this->basicSalary[$employeeId];
    }

    public function overtimeAmount(int $employeeId, int $minutes, float $rate): ?float
    {
        $basic = $this->basicSalary($employeeId);

        return $basic !== null ? round($basic / 225 * $rate * $minutes / 60, 2) : null;
    }
}
