<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Employee self-service payslips (only approved / paid runs) and P9A. */
class PayslipController extends PayrollController
{
    public function __construct(private PayslipService $payslips) {}

    private function myLine(Request $request, int $id): PayrollRunEmployee
    {
        $me = $this->me($request);
        $line = PayrollRunEmployee::withoutGlobalScopes()->with('run')->whereKey($id)->where('employee_id', $me->id)->first();
        abort_unless($line && in_array($line->run?->status, ['approved', 'paid'], true), 404);

        return $line;
    }

    public function index(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $page = PayrollRunEmployee::withoutGlobalScopes()->where('employee_id', $me->id)
            ->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid'])
                ->when($request->filled('year'), fn ($w) => $w->whereYear('period', $request->integer('year'))))
            ->with('run:id,title,period,pay_date,status,type,off_cycle_reason')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_employees.payroll_run_id')
            ->orderByDesc('payroll_runs.period')->orderByDesc('payroll_runs.id')
            ->select('payroll_run_employees.*')
            ->paginate(min($request->integer('per_page', 12) ?: 12, 60));

        $years = PayrollRunEmployee::withoutGlobalScopes()->where('employee_id', $me->id)
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_run_employees.payroll_run_id')
            ->whereIn('payroll_runs.status', ['approved', 'paid'])->selectRaw('DISTINCT YEAR(payroll_runs.period) as y')->orderByDesc('y')->pluck('y');

        return response()->json(array_merge($page->through(fn ($l) => [
            'id' => $l->id,
            'run' => ['id' => $l->run->id, 'title' => $l->run->title, 'period' => $l->run->period->format('Y-m'), 'period_label' => $l->run->period->format('F Y'),
                'pay_date' => $l->run->pay_date?->toDateString(), 'status' => $l->run->status, 'type' => $l->run->type, 'off_cycle_reason' => $l->run->off_cycle_reason],
            'gross_pay' => $l->gross_pay, 'total_deductions' => $l->total_deductions, 'net_pay' => $l->net_pay, 'paye' => $l->paye,
        ])->toArray(), ['years' => $years]));
    }

    public function show(Request $request, int $line): JsonResponse
    {
        return response()->json(['data' => $this->payslips->data($this->myLine($request, $line))]);
    }

    public function pdf(Request $request, int $line)
    {
        $l = $this->myLine($request, $line);
        activity('payroll')->performedOn($l)->causedBy($request->user())->log('Payslip downloaded');

        return $this->payslips->pdf($l)->download($this->payslips->filename($l));
    }

    public function myP9a(Request $request, int $year)
    {
        $me = $this->me($request);
        abort_unless($year >= 2000 && $year <= now()->year, 404);

        return $request->query('format') === 'json'
            ? response()->json(['data' => $this->payslips->p9a($me, $year)])
            : $this->payslips->p9aPdf($me, $year)->download("P9A-{$year}-".($me->employee_number ?: $me->id).'.pdf');
    }

    /** Staff: P9A for any employee in the company. */
    public function p9a(Request $request, Employee $employee, int $year)
    {
        abort_unless($year >= 2000 && $year <= now()->year, 404);

        return $request->query('format') === 'json'
            ? response()->json(['data' => $this->payslips->p9a($employee, $year)])
            : $this->payslips->p9aPdf($employee, $year)->download("P9A-{$year}-".($employee->employee_number ?: $employee->id).'.pdf');
    }
}
