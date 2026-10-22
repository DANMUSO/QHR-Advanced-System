<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Claims\Models\MedicalClaim;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance report: per-employee totals of approved medical claims, staff expenses and salary advances
 * for a year (optionally a month). Expenses/advances are included only when those modules are installed.
 */
class ReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rows($request)->values()]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->rows($request);
        $name = 'finance-report-'.$request->integer('year', now()->year).($request->filled('month') ? '-'.str_pad($request->input('month'), 2, '0', STR_PAD_LEFT) : '').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Employee', 'Employee No.', 'Department', 'Expenses (KES)', 'Claims (KES)', 'Advances (KES)', 'Total (KES)']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['employee_name'], $r['employee_number'], $r['department'], $r['expenses_total'], $r['claims_total'], $r['advances_total'], $r['total']]);
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function rows(Request $request): Collection
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);
        $year = (int) ($data['year'] ?? now()->year);
        $month = $data['month'] ?? null;
        $companyId = Tenancy::companyId();

        $period = function ($q, string $column) use ($year, $month) {
            $q->whereYear($column, $year);
            if ($month) {
                $q->whereMonth($column, $month);
            }
        };

        $claims = MedicalClaim::query()
            ->whereIn('status', MedicalClaim::APPROVED_STATUSES)
            ->tap(fn ($q) => $period($q, 'claim_date'))
            ->selectRaw('employee_id, SUM(COALESCE(approved_amount, total_amount)) total')
            ->groupBy('employee_id')->pluck('total', 'employee_id');

        $expenses = $this->sumIfTable('expense_claims', ['approved', 'paid', 'reimbursed'], 'amount', 'expense_date', $companyId, $period)
            ?? $this->sumIfTable('expenses', ['approved', 'paid', 'reimbursed'], 'amount', 'expense_date', $companyId, $period)
            ?? collect();
        $advances = $this->sumIfTable('payroll_salary_advances', ['approved', 'recovered', 'paid'], 'amount', 'created_at', $companyId, $period) ?? collect();

        $ids = $claims->keys()->merge($expenses->keys())->merge($advances->keys())->unique();
        if ($ids->isEmpty()) {
            return collect();
        }

        return Employee::withTrashed()->whereIn('id', $ids)->with('department:id,name')
            ->get(['id', 'first_name', 'last_name', 'employee_number', 'department_id'])
            ->map(function (Employee $e) use ($claims, $expenses, $advances) {
                $c = round((float) ($claims[$e->id] ?? 0), 2);
                $x = round((float) ($expenses[$e->id] ?? 0), 2);
                $a = round((float) ($advances[$e->id] ?? 0), 2);

                return [
                    'id' => $e->id,
                    'employee_name' => $e->full_name,
                    'employee_number' => $e->employee_number,
                    'department' => $e->department?->name ?? '—',
                    'expenses_total' => $x,
                    'claims_total' => $c,
                    'advances_total' => $a,
                    'total' => round($c + $x + $a, 2),
                ];
            })
            ->sortByDesc('total')->values();
    }

    /** Sums an amount column per employee if the table (and columns) exist; null when the module isn't installed. */
    private function sumIfTable(string $table, array $statuses, string $amount, string $dateColumn, ?int $companyId, callable $period): ?Collection
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumns($table, ['employee_id', $amount])) {
            return null;
        }
        $dateColumn = Schema::hasColumn($table, $dateColumn) ? $dateColumn : 'created_at';
        $q = DB::table($table)->selectRaw("employee_id, SUM({$amount}) total")->groupBy('employee_id');
        if (Schema::hasColumn($table, 'status')) {
            $q->whereIn('status', $statuses);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($companyId && Schema::hasColumn($table, 'company_id')) {
            $q->where('company_id', $companyId);
        }
        $period($q, $dateColumn);

        return $q->pluck('total', 'employee_id');
    }
}
