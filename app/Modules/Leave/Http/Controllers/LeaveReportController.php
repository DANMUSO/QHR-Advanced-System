<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Leave reports (HR-FEATURES §3.7). Every report returns {report, title, columns, data, summary}
 * and filters by year, department, branch, leave type and search; /export streams the same rows as CSV.
 */
class LeaveReportController extends Controller
{
    public const REPORTS = [
        'balances' => 'Leave balances',
        'taken' => 'Leave taken per employee',
        'monthly' => 'Days taken by type and month',
        'department' => 'Leave by department',
        'rollover' => 'Roll-over (carried forward)',
        'forfeiture' => 'Forfeiture',
        'handover' => 'Handover report',
        'consolidated' => 'Consolidated leave',
        'forensic' => 'Forensic analysis',
        'liability' => 'Leave liability',
    ];

    private const MONTHS = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->build($request) + [
            'reports' => collect(self::REPORTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $report = $this->build($request);
        $filename = 'leave_'.$report['report'].'_'.$request->integer('year', now()->year).'.csv';

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($report['columns'], 'label'));
            foreach ($report['data'] as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c['key']] ?? '', $report['columns']));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function build(Request $request): array
    {
        $report = array_key_exists($request->query('report'), self::REPORTS) ? $request->query('report') : 'balances';
        $year = $request->integer('year') ?: now()->year;
        [$columns, $rows] = $this->{$report}($request, $year);

        return ['report' => $report, 'title' => self::REPORTS[$report], 'year' => $year, 'columns' => $columns, 'data' => $rows->values(), 'summary' => $this->summary($columns, $rows)];
    }

    /** Totals of numeric columns. */
    private function summary(array $columns, Collection $rows): array
    {
        $totals = [];
        foreach ($columns as $c) {
            if (($c['align'] ?? null) === 'right' && empty($c['no_total'])) {
                $totals[$c['key']] = round($rows->sum(fn ($r) => (float) ($r[$c['key']] ?? 0)), 2);
            }
        }

        return ['rows' => $rows->count(), 'totals' => $totals];
    }

    private function employees(Request $request)
    {
        return Employee::query()
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('search'), fn ($q) => $q->search($request->query('search')));
    }

    private function filterByEmployee($query, Request $request)
    {
        if ($request->hasAny(['department_id', 'branch_id', 'search']) && array_filter($request->only(['department_id', 'branch_id', 'search']))) {
            $query->whereIn('employee_id', $this->employees($request)->select('id'));
        }

        return $query->when($request->filled('leave_type_id'), fn ($q) => $q->where('leave_type_id', $request->integer('leave_type_id')));
    }

    private function num(array $col): array
    {
        return $col + ['align' => 'right'];
    }

    private function balances(Request $request, int $year): array
    {
        $pending = $this->pendingMap($request, $year);
        $rows = $this->filterByEmployee(LeaveBalance::where('year', $year), $request)
            ->whereHas('employee', fn ($e) => $e->where('status', 'active'))
            ->with(['employee:id,first_name,last_name,employee_number,department_id,branch_id', 'employee.department:id,name', 'leaveType:id,name,code,sort_order'])
            ->get()->sortBy(fn ($b) => [$b->employee?->first_name, $b->leaveType?->sort_order])
            ->map(fn (LeaveBalance $b) => [
                'employee_id' => $b->employee_id,
                'employee_number' => $b->employee?->employee_number,
                'employee_name' => $b->employee?->full_name,
                'department' => $b->employee?->department?->name,
                'leave_type' => $b->leaveType?->name,
                'entitled' => round($b->entitled + $b->accrued, 2),
                'carried_forward' => $b->carried_forward,
                'adjusted' => $b->adjusted,
                'taken' => $b->taken,
                'forfeited' => $b->forfeited,
                'pending' => $pending[$b->employee_id.'-'.$b->leave_type_id] ?? 0,
                'balance' => $b->available,
            ]);

        return [[
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            $this->num(['key' => 'entitled', 'label' => 'Entitled / accrued']),
            $this->num(['key' => 'carried_forward', 'label' => 'Carried fwd']),
            $this->num(['key' => 'adjusted', 'label' => 'Adjusted']),
            $this->num(['key' => 'taken', 'label' => 'Taken']),
            $this->num(['key' => 'forfeited', 'label' => 'Forfeited']),
            $this->num(['key' => 'pending', 'label' => 'Pending']),
            $this->num(['key' => 'balance', 'label' => 'Balance']),
        ], $rows];
    }

    /** employee-ledgerType => pending days. */
    private function pendingMap(Request $request, int $year): array
    {
        $types = LeaveType::get(['id', 'balance_type_id'])->mapWithKeys(fn ($t) => [$t->id => $t->balance_type_id ?? $t->id]);

        return LeaveRequest::whereIn('status', LeaveRequest::OPEN)->whereYear('start_date', $year)
            ->get(['employee_id', 'leave_type_id', 'days'])
            ->groupBy(fn ($r) => $r->employee_id.'-'.($types[$r->leave_type_id] ?? $r->leave_type_id))
            ->map(fn ($g) => round($g->sum('days'), 2))->all();
    }

    private function taken(Request $request, int $year): array
    {
        $balances = LeaveBalance::where('year', $year)->get()->keyBy(fn ($b) => $b->employee_id.'-'.$b->leave_type_id);
        $types = LeaveType::get()->keyBy('id');
        $rows = $this->filterByEmployee(LeaveRequest::whereYear('start_date', $year), $request)
            ->with(['employee:id,first_name,last_name,employee_number,department_id', 'employee.department:id,name'])
            ->get()->groupBy(fn ($r) => $r->employee_id.'-'.$r->leave_type_id)
            ->map(function (Collection $g) use ($balances, $types) {
                $first = $g->first();
                $type = $types[$first->leave_type_id] ?? null;
                $ledgerId = $type?->balance_type_id ?? $first->leave_type_id;
                $balance = $balances[$first->employee_id.'-'.$ledgerId] ?? null;

                return [
                    'employee_number' => $first->employee?->employee_number,
                    'employee_name' => $first->employee?->full_name,
                    'department' => $first->employee?->department?->name,
                    'leave_type' => $type?->name,
                    'entitled' => $balance ? $balance->earned : null,
                    'applied' => $g->count(),
                    'approved' => round($g->filter(fn ($r) => in_array($r->status, [LeaveRequest::APPROVED, LeaveRequest::RECALLED], true))->sum('days'), 2),
                    'pending' => round($g->whereIn('status', LeaveRequest::OPEN)->sum('days'), 2),
                    'rejected' => $g->whereIn('status', [LeaveRequest::REJECTED, LeaveRequest::HANDOVER_REJECTED])->count(),
                    'balance' => $balance?->available,
                ];
            })->sortBy('employee_name');

        return [[
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            $this->num(['key' => 'entitled', 'label' => 'Entitled', 'no_total' => true]),
            $this->num(['key' => 'applied', 'label' => 'Applications']),
            $this->num(['key' => 'approved', 'label' => 'Days approved']),
            $this->num(['key' => 'pending', 'label' => 'Days pending']),
            $this->num(['key' => 'rejected', 'label' => 'Rejected']),
            $this->num(['key' => 'balance', 'label' => 'Balance', 'no_total' => true]),
        ], $rows];
    }

    private function monthly(Request $request, int $year): array
    {
        $rows = $this->filterByEmployee(LeaveRequest::taken()->whereYear('start_date', $year), $request)
            ->with('leaveType:id,name,sort_order')->get()->groupBy('leave_type_id')
            ->map(function (Collection $g) {
                $row = ['leave_type' => $g->first()->leaveType?->name, 'sort' => $g->first()->leaveType?->sort_order];
                foreach (self::MONTHS as $i => $m) {
                    $row[$m] = round($g->filter(fn ($r) => $r->start_date->month === $i + 1)->sum('days'), 2);
                }
                $row['total'] = round($g->sum('days'), 2);
                $row['employees'] = $g->unique('employee_id')->count();

                return $row;
            })->sortBy('sort');

        $cols = [['key' => 'leave_type', 'label' => 'Leave type']];
        foreach (self::MONTHS as $m) {
            $cols[] = $this->num(['key' => $m, 'label' => ucfirst($m)]);
        }
        $cols[] = $this->num(['key' => 'total', 'label' => 'Total days']);
        $cols[] = $this->num(['key' => 'employees', 'label' => 'Employees']);

        return [$cols, $rows];
    }

    private function department(Request $request, int $year): array
    {
        $headcount = $this->employees($request)->active()->with('department:id,name')->get()->groupBy(fn ($e) => $e->department?->name ?? 'Unassigned')->map->count();
        $rows = $this->filterByEmployee(LeaveRequest::whereYear('start_date', $year), $request)
            ->with('employee:id,department_id', 'employee.department:id,name')->get()
            ->groupBy(fn ($r) => $r->employee?->department?->name ?? 'Unassigned')
            ->map(function (Collection $g, $dept) use ($headcount) {
                $taken = $g->filter(fn ($r) => in_array($r->status, [LeaveRequest::APPROVED, LeaveRequest::RECALLED], true));
                $staff = $headcount[$dept] ?? 0;

                return [
                    'department' => $dept,
                    'headcount' => $staff,
                    'requests' => $g->count(),
                    'days_taken' => round($taken->sum('days'), 2),
                    'days_pending' => round($g->whereIn('status', LeaveRequest::OPEN)->sum('days'), 2),
                    'rejected' => $g->where('status', LeaveRequest::REJECTED)->count(),
                    'avg_per_employee' => $staff ? round($taken->sum('days') / $staff, 2) : 0,
                ];
            })->sortBy('department');

        return [[
            ['key' => 'department', 'label' => 'Department'],
            $this->num(['key' => 'headcount', 'label' => 'Headcount']),
            $this->num(['key' => 'requests', 'label' => 'Requests']),
            $this->num(['key' => 'days_taken', 'label' => 'Days taken']),
            $this->num(['key' => 'days_pending', 'label' => 'Days pending']),
            $this->num(['key' => 'rejected', 'label' => 'Rejected']),
            $this->num(['key' => 'avg_per_employee', 'label' => 'Avg days / employee', 'no_total' => true]),
        ], $rows];
    }

    private function ledgerReport(Request $request, int $year, string $type): Collection
    {
        return $this->filterByEmployee(LeaveTransaction::where('type', $type)->whereYear('effective_date', $year), $request)
            ->with(['employee:id,first_name,last_name,employee_number,department_id', 'employee.department:id,name', 'leaveType:id,name'])
            ->orderBy('effective_date')->get()
            ->map(fn (LeaveTransaction $t) => [
                'employee_number' => $t->employee?->employee_number,
                'employee_name' => $t->employee?->full_name,
                'department' => $t->employee?->department?->name,
                'leave_type' => $t->leaveType?->name,
                'days' => abs($t->days),
                'date' => $t->effective_date->toDateString(),
                'reason' => $t->reason,
            ]);
    }

    private function rollover(Request $request, int $year): array
    {
        return [[
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            $this->num(['key' => 'days', 'label' => 'Days carried into '.$year]),
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'reason', 'label' => 'Note'],
        ], $this->ledgerReport($request, $year, 'carry_forward')];
    }

    private function forfeiture(Request $request, int $year): array
    {
        return [[
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            $this->num(['key' => 'days', 'label' => 'Days forfeited']),
            ['key' => 'date', 'label' => 'Date'],
            ['key' => 'reason', 'label' => 'Note'],
        ], $this->ledgerReport($request, $year, 'forfeiture')];
    }

    private function handover(Request $request, int $year): array
    {
        $label = fn ($s) => $s ? ucfirst($s) : '—';
        $rows = $this->filterByEmployee(LeaveRequest::whereYear('start_date', $year)->where(fn ($q) => $q->whereNotNull('handover1_id')->orWhereNotNull('handover2_id')), $request)
            ->with(['employee:id,first_name,last_name,employee_number', 'leaveType:id,name', 'handover1:id,first_name,last_name', 'handover2:id,first_name,last_name', 'supervisor:id,first_name,last_name'])
            ->orderByDesc('start_date')->get()
            ->map(fn (LeaveRequest $r) => [
                'employee_name' => $r->employee?->full_name,
                'leave_type' => $r->leaveType?->name,
                'start_date' => $r->start_date->toDateString(),
                'end_date' => $r->end_date->toDateString(),
                'days' => $r->days,
                'handover1' => $r->handover1?->full_name,
                'handover1_status' => $label($r->handover1_status),
                'handover2' => $r->handover2?->full_name,
                'handover2_status' => $r->handover2_id ? $label($r->handover2_status) : '',
                'supervisor' => $r->supervisor?->full_name,
                'status' => LeaveRequest::STATUSES[$r->status] ?? $r->status,
            ]);

        return [[
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            ['key' => 'start_date', 'label' => 'From'],
            ['key' => 'end_date', 'label' => 'To'],
            $this->num(['key' => 'days', 'label' => 'Days']),
            ['key' => 'handover1', 'label' => 'Handover 1'],
            ['key' => 'handover1_status', 'label' => 'H1 status'],
            ['key' => 'handover2', 'label' => 'Handover 2'],
            ['key' => 'handover2_status', 'label' => 'H2 status'],
            ['key' => 'supervisor', 'label' => 'Supervisor'],
            ['key' => 'status', 'label' => 'Request status'],
        ], $rows];
    }

    /** Outstanding leave days (available × employees) per department and type — the liability carried by the company. */
    private function consolidated(Request $request, int $year): array
    {
        $types = LeaveType::get()->keyBy('id');
        $rows = $this->filterByEmployee(LeaveBalance::where('year', $year), $request)
            ->whereHas('employee', fn ($e) => $e->where('status', 'active'))
            ->with(['employee:id,first_name,last_name,employee_number,department_id', 'employee.department:id,name', 'leaveType:id,name,code'])
            ->get()
            ->groupBy('employee_id')
            ->map(function (Collection $g) {
                $first = $g->first();
                $row = [
                    'employee_number' => $first->employee?->employee_number,
                    'employee_name' => $first->employee?->full_name,
                    'department' => $first->employee?->department?->name,
                    'total_taken' => round($g->sum('taken'), 2),
                    'total_balance' => round($g->sum('available'), 2),
                ];
                foreach ($g as $b) {
                    $code = strtolower($b->leaveType?->code ?? 'other');
                    $row[$code.'_balance'] = $b->available;
                    $row[$code.'_taken'] = $b->taken;
                }

                return $row;
            })->sortBy('employee_name');

        // Build dynamic columns from the leave types present
        $typeCodes = $types->map(fn ($t) => strtolower($t->code))->unique()->values();
        $cols = [
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
        ];
        foreach ($typeCodes as $code) {
            $name = $types->first(fn ($t) => strtolower($t->code) === $code)?->name ?? $code;
            $cols[] = $this->num(['key' => $code.'_balance', 'label' => $name.' bal.', 'no_total' => true]);
        }
        $cols[] = $this->num(['key' => 'total_taken', 'label' => 'Total taken']);
        $cols[] = $this->num(['key' => 'total_balance', 'label' => 'Total balance', 'no_total' => true]);

        return [$cols, $rows];
    }

    /** Full transaction ledger per employee — the forensic audit trail. */
    private function forensic(Request $request, int $year): array
    {
        $rows = $this->filterByEmployee(LeaveTransaction::whereYear('effective_date', $year), $request)
            ->with(['employee:id,first_name,last_name,employee_number,department_id', 'employee.department:id,name', 'leaveType:id,name', 'balance:id,available'])
            ->orderBy('employee_id')->orderBy('effective_date')
            ->get()
            ->map(fn (LeaveTransaction $t) => [
                'employee_number' => $t->employee?->employee_number,
                'employee_name' => $t->employee?->full_name,
                'department' => $t->employee?->department?->name,
                'leave_type' => $t->leaveType?->name,
                'transaction_type' => ucfirst(str_replace('_', ' ', $t->type)),
                'days' => $t->days,
                'balance_after' => $t->balance?->available,
                'description' => $t->reason,
                'created_at' => $t->effective_date->toDateString(),
            ]);

        return [[
            ['key' => 'employee_number', 'label' => 'No.'],
            ['key' => 'employee_name', 'label' => 'Employee'],
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            ['key' => 'transaction_type', 'label' => 'Transaction'],
            $this->num(['key' => 'days', 'label' => 'Days']),
            $this->num(['key' => 'balance_after', 'label' => 'Balance after', 'no_total' => true]),
            ['key' => 'description', 'label' => 'Description'],
            ['key' => 'created_at', 'label' => 'Date'],
        ], $rows];
    }

    /** Outstanding leave days (available × employees) per department and type — the liability carried by the company. */
    private function liability(Request $request, int $year): array
    {
        $rows = $this->filterByEmployee(LeaveBalance::where('year', $year), $request)
            ->whereHas('employee', fn ($e) => $e->where('status', 'active'))
            ->whereHas('leaveType', fn ($t) => $t->where('paid', '!=', 'unpaid'))
            ->with(['employee:id,department_id', 'employee.department:id,name', 'leaveType:id,name,sort_order'])->get()
            ->groupBy(fn ($b) => ($b->employee?->department?->name ?? 'Unassigned').'|'.$b->leaveType?->name)
            ->map(function (Collection $g, $key) {
                [$dept, $type] = explode('|', $key);
                $available = round($g->sum(fn ($b) => max(0, $b->available)), 2);

                return [
                    'department' => $dept,
                    'leave_type' => $type,
                    'sort' => $g->first()->leaveType?->sort_order,
                    'employees' => $g->count(),
                    'days_outstanding' => $available,
                    'avg_days' => $g->count() ? round($available / $g->count(), 2) : 0,
                ];
            })->sortBy(fn ($r) => [$r['department'], $r['sort']]);

        return [[
            ['key' => 'department', 'label' => 'Department'],
            ['key' => 'leave_type', 'label' => 'Leave type'],
            $this->num(['key' => 'employees', 'label' => 'Employees']),
            $this->num(['key' => 'days_outstanding', 'label' => 'Days outstanding']),
            $this->num(['key' => 'avg_days', 'label' => 'Avg / employee', 'no_total' => true]),
        ], $rows];
    }
}
