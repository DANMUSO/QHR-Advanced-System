<?php

namespace App\Modules\Analytics\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Support\Tenancy;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Workforce analytics computed from live data. Every method is scoped to the current company
 * (Employee/Department use BelongsToCompany; raw table queries filter by Tenancy::companyId()).
 */
class AnalyticsService
{
    private function scope($query, string $column = 'company_id')
    {
        $companyId = Tenancy::companyId();

        return $companyId ? $query->where($column, $companyId) : $query;
    }

    private function employeeIds(): Collection
    {
        return Employee::withTrashed()->pluck('id');
    }

    // ── Headcount movement ───────────────────────────────────────────────────────────────────

    public function headcount(int $year): array
    {
        $employees = Employee::withTrashed()->whereNull('previous_employee_id')->whereNotNull('date_of_joining')->get(['id', 'date_of_joining']);
        $exits = EmployeeExit::whereIn('employee_id', $employees->pluck('id'))->get(['employee_id', 'exit_date'])->keyBy('employee_id');

        $activeOn = fn (Carbon $date) => $employees->filter(fn ($e) => $e->date_of_joining->lte($date)
            && (! isset($exits[$e->id]) || $exits[$e->id]->exit_date->gt($date)))->count();

        $rows = [];
        foreach (range(1, 12) as $m) {
            $start = Carbon::create($year, $m, 1)->startOfDay();
            if ($start->isFuture()) {
                break;
            }
            $end = $start->copy()->endOfMonth();
            $opening = $activeOn($start->copy()->subDay());
            $hires = $employees->filter(fn ($e) => $e->date_of_joining->between($start, $end))->count();
            $out = $exits->filter(fn ($x) => $x->exit_date->between($start, $end))->count();
            $closing = $activeOn(min($end, now()));
            $avg = ($opening + $closing) / 2;

            $rows[] = [
                'month' => $start->format('M Y'), 'opening' => $opening, 'new_hires' => $hires, 'exits' => $out,
                'closing' => $closing, 'turnover_rate' => $avg > 0 ? round($out / $avg * 100, 1) : 0,
            ];
        }

        return $rows;
    }

    // ── Payroll cost ─────────────────────────────────────────────────────────────────────────

    public function payrollCost(int $year): array
    {
        if (! Schema::hasTable('payroll_runs')) {
            return ['rows' => [], 'summary' => []];
        }
        $runs = $this->scope(DB::table('payroll_runs'))
            ->whereYear('period', $year)->where('type', 'regular')
            ->whereIn('status', ['calculated', 'reviewed', 'approved', 'paid', 'locked'])
            ->selectRaw('period, SUM(total_gross) gross, SUM(total_deductions) deductions, SUM(total_net) net, SUM(employee_count) headcount, SUM(total_employer_cost) employer_cost')
            ->groupBy('period')->orderBy('period')->get();

        $rows = $runs->map(fn ($r) => [
            'month' => Carbon::parse($r->period)->format('M Y'),
            'gross_payroll' => round((float) $r->gross, 2),
            'total_deductions' => round((float) $r->deductions, 2),
            'net_payroll' => round((float) $r->net, 2),
            'employer_cost' => round((float) $r->employer_cost, 2),
            'headcount' => (int) $r->headcount,
            'cost_per_employee' => $r->headcount > 0 ? round((float) $r->gross / $r->headcount, 2) : 0,
        ])->all();

        $gross = array_sum(array_column($rows, 'gross_payroll'));
        $months = max(1, count($rows));

        return ['rows' => $rows, 'summary' => $rows ? [
            'Total gross (YTD)' => 'KES '.number_format($gross),
            'Total net (YTD)' => 'KES '.number_format(array_sum(array_column($rows, 'net_payroll'))),
            'Average monthly cost' => 'KES '.number_format($gross / $months),
            'Avg cost per employee' => 'KES '.number_format(array_sum(array_column($rows, 'cost_per_employee')) / $months),
        ] : []];
    }

    // ── Absenteeism ──────────────────────────────────────────────────────────────────────────

    public function absenteeism(int $year): array
    {
        $from = Carbon::create($year, 1, 1);
        $to = min(Carbon::create($year, 12, 31), now());
        $workingDays = collect(CarbonPeriod::create($from, $to))->filter(fn ($d) => ! $d->isWeekend())->count();

        $byDept = Employee::active()->with('department:id,name')->get(['id', 'department_id'])->groupBy(fn ($e) => $e->department?->name ?? 'Unassigned');
        $absent = Schema::hasTable('attendance_days')
            ? $this->scope(DB::table('attendance_days'))->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->where(fn ($q) => $q->where('status', 'absent')->orWhere('override_status', 'absent'))
                ->selectRaw('employee_id, COUNT(*) c')->groupBy('employee_id')->pluck('c', 'employee_id')
            : collect();
        $basic = Schema::hasTable('payroll_pay_profiles')
            ? $this->scope(DB::table('payroll_pay_profiles'))->pluck('basic_salary', 'employee_id') : collect();

        return $byDept->map(function ($emps, $dept) use ($absent, $basic, $workingDays) {
            $absentDays = $emps->sum(fn ($e) => (int) ($absent[$e->id] ?? 0));
            $available = $emps->count() * $workingDays;
            $cost = $emps->sum(fn ($e) => ((float) ($basic[$e->id] ?? 0) / 22) * (int) ($absent[$e->id] ?? 0));

            return [
                'department' => $dept, 'headcount' => $emps->count(), 'available_days' => $available,
                'absent_days' => $absentDays, 'absenteeism_rate' => $available ? round($absentDays / $available * 100, 2) : 0,
                'cost_impact' => round($cost, 2),
            ];
        })->sortByDesc('absenteeism_rate')->values()->all();
    }

    // ── Gender pay gap ───────────────────────────────────────────────────────────────────────

    public function payGap(): array
    {
        if (! Schema::hasTable('payroll_pay_profiles')) {
            return [];
        }
        $basic = $this->scope(DB::table('payroll_pay_profiles'))->pluck('basic_salary', 'employee_id');
        $employees = Employee::active()->with('department:id,name')->get(['id', 'gender', 'department_id'])
            ->filter(fn ($e) => isset($basic[$e->id]));

        $row = function (string $label, Collection $emps) use ($basic) {
            $m = $emps->where('gender', 'male')->map(fn ($e) => (float) $basic[$e->id]);
            $f = $emps->where('gender', 'female')->map(fn ($e) => (float) $basic[$e->id]);
            $mAvg = $m->avg() ?: 0;
            $fAvg = $f->avg() ?: 0;

            return [
                'department' => $label, 'male_count' => $m->count(), 'female_count' => $f->count(),
                'male_avg_salary' => round($mAvg), 'female_avg_salary' => round($fAvg),
                'gap' => round($mAvg - $fAvg),
                'pay_gap_pct' => $mAvg > 0 && $fAvg > 0 ? round(($mAvg - $fAvg) / $mAvg * 100, 1) : null,
            ];
        };

        $rows = $employees->groupBy(fn ($e) => $e->department?->name ?? 'Unassigned')
            ->map(fn ($emps, $dept) => $row($dept, $emps))->sortBy('department')->values();

        return $rows->push($row('All departments', $employees))->all();
    }

    // ── Attrition risk ───────────────────────────────────────────────────────────────────────

    /** Explainable rule-based risk score (0–100) per active employee. */
    public function attrition(int $limit = 200): array
    {
        $since = now()->subDays(60)->toDateString();
        $late = Schema::hasTable('attendance_days')
            ? $this->scope(DB::table('attendance_days'))->where('date', '>=', $since)->where('status', 'late')->selectRaw('employee_id, COUNT(*) c')->groupBy('employee_id')->pluck('c', 'employee_id') : collect();
        $absent = Schema::hasTable('attendance_days')
            ? $this->scope(DB::table('attendance_days'))->where('date', '>=', $since)->where('status', 'absent')->selectRaw('employee_id, COUNT(*) c')->groupBy('employee_id')->pluck('c', 'employee_id') : collect();
        $lastRaise = Schema::hasTable('payroll_salary_history')
            ? $this->scope(DB::table('payroll_salary_history'))->selectRaw('employee_id, MAX(effective_date) d')->groupBy('employee_id')->pluck('d', 'employee_id') : collect();
        $onPip = Schema::hasTable('perf_pips')
            ? $this->scope(DB::table('perf_pips'))->where('status', 'active')->pluck('employee_id')->flip() : collect();

        return Employee::active()->with('department:id,name')->get()->map(function (Employee $e) use ($late, $absent, $lastRaise, $onPip) {
            $score = 0;
            $factors = [];
            $tenure = $e->date_of_joining ? $e->date_of_joining->diffInDays(now()) / 365 : 0;

            if ($tenure < 1) { $score += 15; $factors[] = 'Under 1 year in role'; }
            elseif ($tenure >= 2 && $tenure < 4) { $score += 8; $factors[] = '2–4 years tenure (common exit window)'; }
            if ($e->contract_type !== 'permanent' && $e->contract_end_date && $e->contract_end_date->between(now(), now()->addDays(90))) { $score += 20; $factors[] = 'Contract ends within 90 days'; }
            if (($late[$e->id] ?? 0) >= 6) { $score += 15; $factors[] = ($late[$e->id]).' late arrivals in 60 days'; }
            if (($absent[$e->id] ?? 0) >= 3) { $score += 20; $factors[] = ($absent[$e->id]).' absences in 60 days'; }
            $raise = isset($lastRaise[$e->id]) ? Carbon::parse($lastRaise[$e->id]) : $e->date_of_joining;
            if ($raise && $raise->lt(now()->subMonths(24)) && $tenure >= 2) { $score += 15; $factors[] = 'No pay change in 2+ years'; }
            if (isset($onPip[$e->id])) { $score += 25; $factors[] = 'On a performance improvement plan'; }
            if (! $e->primary_supervisor_id) { $score += 5; $factors[] = 'No supervisor assigned'; }

            $score = min(100, $score);

            return [
                'id' => $e->id, 'employee_name' => $e->full_name, 'photo' => null, 'photo_url' => $e->photo_url,
                'department' => $e->department?->name ?? '—', 'tenure_years' => round($tenure, 1),
                'risk_score' => $score, 'risk_level' => $score >= 50 ? 'high' : ($score >= 25 ? 'medium' : 'low'),
                'key_factors' => $factors ? implode('; ', $factors) : 'No significant risk signals',
            ];
        })->sortByDesc('risk_score')->take($limit)->values()->all();
    }

    // ── Workforce planning ───────────────────────────────────────────────────────────────────

    public function workforce(): array
    {
        $risk = collect($this->attrition(10000))->groupBy('department');
        $planned = Schema::hasTable('recruitment_requisitions')
            ? $this->scope(DB::table('recruitment_requisitions'))->whereNull('deleted_at')->where('status', 'approved')
                ->selectRaw('department_id, SUM(vacancies) v')->groupBy('department_id')->pluck('v', 'department_id') : collect();

        return Department::withCount(['employees' => fn ($q) => $q->where('status', 'active')])->orderBy('name')->get()
            ->map(function (Department $d) use ($risk, $planned) {
                $contractEnds = Employee::active()->where('department_id', $d->id)->where('contract_type', '!=', 'permanent')
                    ->whereBetween('contract_end_date', [now(), now()->addMonths(6)])->count();
                $highRisk = ($risk[$d->name] ?? collect())->where('risk_level', 'high')->count();
                $exits = $contractEnds + (int) ceil($highRisk / 2);
                $hires = (int) ($planned[$d->id] ?? 0);
                $projected = max(0, $d->employees_count + $hires - $exits);

                return [
                    'department' => $d->name, 'current_headcount' => $d->employees_count, 'planned_hires' => $hires,
                    'projected_exits' => $exits, 'projected_headcount' => $projected, 'gap' => $projected - $d->employees_count,
                ];
            })->all();
    }

    // ── Dashboard ────────────────────────────────────────────────────────────────────────────

    public function hrDashboard(?int $pendingApprovals = null): array
    {
        $headcount = Employee::active()->count();
        $monthStart = now()->startOfMonth();
        $ids = $this->employeeIds();
        $exits12 = EmployeeExit::whereIn('employee_id', $ids)->where('exit_date', '>=', now()->subYear())->count();
        $tenure = Employee::active()->whereNotNull('date_of_joining')->selectRaw('AVG(TIMESTAMPDIFF(MONTH, date_of_joining, CURDATE())) m')->value('m');

        $utilisation = null;
        if (Schema::hasTable('leave_balances') && Schema::hasTable('leave_types')) {
            $annual = $this->scope(DB::table('leave_balances')->join('leave_types', 'leave_types.id', '=', 'leave_balances.leave_type_id'), 'leave_balances.company_id')
                ->where('leave_types.code', 'ANNUAL')->where('leave_balances.year', now()->year)
                ->selectRaw('SUM(leave_balances.taken) taken, SUM(leave_balances.entitled + leave_balances.accrued + leave_balances.carried_forward) total')->first();
            $utilisation = $annual && $annual->total > 0 ? round($annual->taken / $annual->total * 100, 1) : null;
        }

        $open = Schema::hasTable('recruitment_requisitions')
            ? (int) $this->scope(DB::table('recruitment_requisitions'))->whereNull('deleted_at')->whereIn('status', ['approved', 'pending_approval'])->sum('vacancies') : 0;

        return [
            'headcount' => $headcount,
            'new_hires_mtd' => Employee::where('date_of_joining', '>=', $monthStart)->whereNull('previous_employee_id')->count(),
            'exits_mtd' => EmployeeExit::whereIn('employee_id', $ids)->where('exit_date', '>=', $monthStart)->count(),
            'turnover_rate' => $headcount ? round($exits12 / max(1, $headcount + $exits12 / 2) * 100, 1) : 0,
            'avg_tenure' => $tenure ? round($tenure / 12, 1) : null,
            'leave_utilisation' => $utilisation,
            'open_positions' => $open,
            'pending_approvals' => $pendingApprovals,
            'by_department' => Employee::active()->toBase()->leftJoin('departments', 'departments.id', '=', 'employees.department_id')
                ->selectRaw('COALESCE(departments.name, "Unassigned") name, COUNT(*) count')->groupBy('name')->orderByDesc('count')->get(),
        ];
    }

    // ── Insights ─────────────────────────────────────────────────────────────────────────────

    /** Rule-based findings drawn from the analytics above, each with a recommendation. */
    public function insights(): array
    {
        $out = [];
        $add = function (string $category, string $title, string $description, string $recommendation) use (&$out) {
            $out[] = ['id' => count($out) + 1, 'category' => $category, 'title' => $title, 'description' => $description,
                'recommendation' => $recommendation, 'generated_at' => now()->toIso8601String()];
        };

        $risk = collect($this->attrition(10000));
        $high = $risk->where('risk_level', 'high');
        if ($high->count()) {
            $topDept = $high->countBy('department')->sortDesc();
            $add('attrition', "{$high->count()} employees show high attrition risk",
                'Most are in '.$topDept->keys()->first()." ({$topDept->first()}). Common signals: ".$high->pluck('key_factors')->flatMap(fn ($f) => explode('; ', $f))->countBy()->sortDesc()->keys()->take(3)->implode(', ').'.',
                'Schedule stay interviews with their supervisors this month and review pay for anyone without a change in 2+ years.');
        }

        $abs = collect($this->absenteeism(now()->year))->filter(fn ($r) => $r['headcount'] >= 3);
        $avgRate = $abs->avg('absenteeism_rate') ?: 0;
        foreach ($abs->filter(fn ($r) => $avgRate > 0 && $r['absenteeism_rate'] >= $avgRate * 1.5)->take(2) as $r) {
            $add('absenteeism', "Absenteeism in {$r['department']} is above average",
                "{$r['department']} runs at {$r['absenteeism_rate']}% against a company average of ".round($avgRate, 2)."%, costing about KES ".number_format($r['cost_impact']).' this year.',
                'Review attendance patterns with the department head and check for workload or wellbeing issues.');
        }

        $gap = collect($this->payGap())->firstWhere('department', 'All departments');
        if ($gap && $gap['pay_gap_pct'] !== null && abs($gap['pay_gap_pct']) >= 5) {
            $add('compensation', 'Gender pay gap of '.abs($gap['pay_gap_pct']).'%',
                'Average basic pay is KES '.number_format($gap['male_avg_salary']).' for men and KES '.number_format($gap['female_avg_salary']).' for women.',
                'Compare pay within the same positions and grades to see whether the gap is structural or role-mix related.');
        }

        $contracts = Employee::active()->where('contract_type', '!=', 'permanent')->whereBetween('contract_end_date', [now(), now()->addDays(60)])->count();
        if ($contracts) {
            $add('workforce', "{$contracts} contracts expire in the next 60 days",
                'Unrenewed contracts become unplanned exits and can leave roles uncovered.',
                'Decide on renewals now and start requisitions for roles that will not be renewed.');
        }

        $noSup = Employee::active()->whereNull('primary_supervisor_id')->count();
        if ($noSup) {
            $add('compliance', "{$noSup} employees have no supervisor",
                'Their leave, claims and KPI approvals fall back to HR and can get stuck.',
                'Assign a primary supervisor to each of them in the employee directory.');
        }

        $headcount = collect($this->headcount(now()->year));
        $exits = $headcount->sum('exits');
        $hires = $headcount->sum('new_hires');
        if ($exits > $hires && $exits >= 3) {
            $add('workforce', 'More people left than joined this year', "{$exits} exits against {$hires} new hires year to date.",
                'Check exit reasons and prioritise hiring for critical positions.');
        }

        if (! $out) {
            $add('workforce', 'No significant risks detected', 'Attendance, attrition signals, pay equity and contract renewals are all within normal ranges.',
                'Keep monitoring monthly.');
        }

        return $out;
    }
}
