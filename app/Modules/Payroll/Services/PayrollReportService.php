<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollRunItem;
use App\Modules\Payroll\Support\PayrollSettings;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Builds every tabular payroll output as ['title', 'filename', 'columns' => [[key, label, money?]], 'rows', 'totals'].
 * The same table feeds the JSON screens and the CSV downloads.
 */
class PayrollReportService
{
    public const RUN_REPORTS = ['register', 'department', 'branch', 'components', 'employer_cost'];

    public const RETURNS = ['paye' => 'P10 / PAYE schedule', 'nssf' => 'NSSF schedule', 'shif' => 'SHIF schedule', 'ahl' => 'Housing Levy schedule', 'helb' => 'HELB schedule'];

    public const PAYMENT_FILES = ['bank' => 'Bank transfer file', 'mpesa' => 'M-Pesa bulk payment file'];

    /** @return Collection<int, PayrollRunEmployee> */
    public function lines(PayrollRun $run): Collection
    {
        return PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)
            ->with(['employee:id,first_name,last_name,other_names,employee_number,kra_pin,nssf_number,shif_number,helb_number,national_id,phone', 'department:id,name', 'branch:id,name'])
            ->get()->sortBy(fn ($l) => $l->employee?->employee_number)->values();
    }

    private function col(string $key, string $label, bool $money = false): array
    {
        return ['key' => $key, 'label' => $label, 'money' => $money];
    }

    private function table(string $title, string $filename, array $columns, Collection|array $rows): array
    {
        $rows = collect($rows)->values();
        $totals = [];
        foreach ($columns as $c) {
            if ($c['money']) {
                $totals[$c['key']] = round($rows->sum(fn ($r) => (float) ($r[$c['key']] ?? 0)), 2);
            }
        }

        return ['title' => $title, 'filename' => $filename, 'columns' => $columns, 'rows' => $rows->all(), 'totals' => $totals];
    }

    private function emp(PayrollRunEmployee $l): array
    {
        $e = $l->employee;

        return [
            'employee_id' => $l->employee_id, 'employee_number' => $e?->employee_number, 'employee_name' => $e?->full_name,
            'first_name' => $e?->first_name, 'last_name' => $e?->last_name, 'kra_pin' => $e?->kra_pin, 'national_id' => $e?->national_id,
            'department' => $l->department?->name, 'branch' => $l->branch?->name,
        ];
    }

    private function slug(PayrollRun $run, string $what): string
    {
        return $what.'-'.($run->period?->format('Y-m') ?? 'unknown').($run->type === 'off_cycle' ? '-run'.$run->id : '').'.csv';
    }

    // ---------------------------------------------------------------- run reports

    public function runReport(PayrollRun $run, string $type): array
    {
        $lines = $this->lines($run);

        return match ($type) {
            'department', 'branch' => $this->groupSummary($run, $lines, $type),
            'components' => $this->componentBreakdown($run),
            'employer_cost' => $this->table('Employer cost', $this->slug($run, 'employer-cost'), [
                $this->col('employee_number', 'Emp No.'), $this->col('employee_name', 'Employee'), $this->col('department', 'Department'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('nssf_employer', 'NSSF (employer)', true),
                $this->col('ahl_employer', 'Housing levy (employer)', true), $this->col('pension_employer', 'Pension (employer)', true),
                $this->col('employer_cost', 'Total employer cost', true),
            ], $lines->map(fn ($l) => $this->emp($l) + $l->only(['gross_pay', 'nssf_employer', 'ahl_employer', 'pension_employer', 'employer_cost']))),
            default => $this->table('Payroll register', $this->slug($run, 'payroll-register'), [
                $this->col('employee_number', 'Emp No.'), $this->col('employee_name', 'Employee'), $this->col('department', 'Department'),
                $this->col('basic_salary', 'Basic salary', true), $this->col('basic_pay', 'Basic earned', true), $this->col('gross_pay', 'Gross pay', true),
                $this->col('nssf_employee', 'NSSF', true), $this->col('shif', 'SHIF', true), $this->col('ahl_employee', 'Housing levy', true),
                $this->col('pension_employee', 'Pension', true), $this->col('taxable_pay', 'Taxable pay', true), $this->col('paye', 'PAYE', true),
                $this->col('helb', 'HELB', true), $this->col('other_deductions', 'Other deductions', true),
                $this->col('total_deductions', 'Total deductions', true), $this->col('reimbursements', 'Reimbursements', true),
                $this->col('net_pay', 'Net pay', true),
            ], $lines->map(fn ($l) => $this->emp($l) + $l->only(['basic_salary', 'basic_pay', 'gross_pay', 'nssf_employee', 'shif', 'ahl_employee',
                'pension_employee', 'taxable_pay', 'paye', 'helb', 'other_deductions', 'total_deductions', 'reimbursements', 'net_pay']))),
        };
    }

    private function groupSummary(PayrollRun $run, Collection $lines, string $by): array
    {
        $rows = $lines->groupBy(fn ($l) => ($by === 'department' ? $l->department?->name : $l->branch?->name) ?? 'Unassigned')
            ->map(fn ($g, $name) => [
                'group' => $name, 'headcount' => $g->count(),
                'gross_pay' => round($g->sum('gross_pay'), 2), 'paye' => round($g->sum('paye'), 2),
                'statutory' => round($g->sum(fn ($l) => $l->nssf_employee + $l->shif + $l->ahl_employee), 2),
                'reimbursements' => round($g->sum('reimbursements'), 2),
                'net_pay' => round($g->sum('net_pay'), 2), 'employer_cost' => round($g->sum('employer_cost'), 2),
            ])->sortBy('group')->values();

        return $this->table('Summary by '.$by, $this->slug($run, 'summary-by-'.$by), [
            $this->col('group', ucfirst($by)), $this->col('headcount', 'Headcount'), $this->col('gross_pay', 'Gross pay', true),
            $this->col('paye', 'PAYE', true), $this->col('statutory', 'NSSF + SHIF + AHL', true), $this->col('reimbursements', 'Reimbursements', true),
            $this->col('net_pay', 'Net pay', true), $this->col('employer_cost', 'Employer cost', true),
        ], $rows);
    }

    private function componentBreakdown(PayrollRun $run): array
    {
        $rows = PayrollRunItem::whereIn('payroll_run_employee_id', PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)->select('id'))
            ->whereNotIn('category', ['tax', 'relief'])
            ->selectRaw('code, MAX(name) as name, category, MAX(gl_account) as gl_account, COUNT(DISTINCT payroll_run_employee_id) as employees, SUM(amount) as amount')
            ->groupBy('code', 'category')->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => preg_replace('/\s*\(.*$/', '', $r->name), 'category' => $r->category,
                'gl_account' => $r->gl_account, 'employees' => (int) $r->employees, 'amount' => round((float) $r->amount, 2)])
            ->sortBy(fn ($r) => array_search($r['category'], ['earning', 'statutory', 'deduction', 'employer']).$r['code'])->values();

        return $this->table('Component breakdown', $this->slug($run, 'component-breakdown'), [
            $this->col('code', 'Code'), $this->col('name', 'Component'), $this->col('category', 'Category'), $this->col('gl_account', 'GL account'),
            $this->col('employees', 'Employees'), $this->col('amount', 'Amount', true),
        ], $rows);
    }

    // ---------------------------------------------------------------- statutory returns

    public function statutoryReturn(PayrollRun $run, string $type): array
    {
        $lines = $this->lines($run);
        $title = self::RETURNS[$type] ?? 'Return';
        $file = $this->slug($run, $type.'-return');

        return match ($type) {
            'nssf' => $this->table($title, $file, [
                $this->col('employee_number', 'Payroll No.'), $this->col('last_name', 'Surname'), $this->col('first_name', 'Other names'),
                $this->col('national_id', 'ID No.'), $this->col('kra_pin', 'KRA PIN'), $this->col('nssf_number', 'NSSF No.'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('tier1_employee', 'Tier I employee', true), $this->col('tier1_employer', 'Tier I employer', true),
                $this->col('tier2_employee', 'Tier II employee', true), $this->col('tier2_employer', 'Tier II employer', true), $this->col('total', 'Total', true),
            ], $lines->filter(fn ($l) => $l->nssf_employee > 0)->map(fn ($l) => $this->emp($l) + [
                'nssf_number' => $l->employee?->nssf_number, 'gross_pay' => $l->gross_pay,
                'tier1_employee' => $l->nssf_tier1, 'tier1_employer' => $l->nssf_tier1,
                'tier2_employee' => $l->nssf_tier2, 'tier2_employer' => round($l->nssf_employer - $l->nssf_tier1, 2),
                'total' => round($l->nssf_employee + $l->nssf_employer, 2),
            ])),
            'shif' => $this->table($title, $file, [
                $this->col('employee_number', 'Payroll No.'), $this->col('first_name', 'First name'), $this->col('last_name', 'Last name'),
                $this->col('national_id', 'ID No.'), $this->col('kra_pin', 'KRA PIN'), $this->col('shif_number', 'SHIF No.'), $this->col('phone', 'Phone'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('shif', 'Contribution', true),
            ], $lines->filter(fn ($l) => $l->shif > 0)->map(fn ($l) => $this->emp($l) + [
                'shif_number' => $l->employee?->shif_number, 'phone' => $l->employee?->phone, 'gross_pay' => $l->gross_pay, 'shif' => $l->shif,
            ])),
            'ahl' => $this->table($title, $file, [
                $this->col('national_id', 'ID No.'), $this->col('kra_pin', 'KRA PIN'), $this->col('employee_name', 'Name'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('ahl_employee', 'Employee (1.5%)', true), $this->col('ahl_employer', 'Employer (1.5%)', true),
                $this->col('total', 'Total', true),
            ], $lines->filter(fn ($l) => $l->ahl_employee > 0)->map(fn ($l) => $this->emp($l) + [
                'gross_pay' => $l->gross_pay, 'ahl_employee' => $l->ahl_employee, 'ahl_employer' => $l->ahl_employer,
                'total' => round($l->ahl_employee + $l->ahl_employer, 2),
            ])),
            'helb' => $this->table($title, $file, [
                $this->col('employee_number', 'Payroll No.'), $this->col('employee_name', 'Name'), $this->col('national_id', 'ID No.'),
                $this->col('helb_number', 'HELB No.'), $this->col('helb', 'Amount', true),
            ], $lines->filter(fn ($l) => $l->helb > 0)->map(fn ($l) => $this->emp($l) + ['helb_number' => $l->employee?->helb_number, 'helb' => $l->helb])),
            default => $this->table($title, $file, [
                $this->col('kra_pin', 'PIN of employee'), $this->col('employee_name', 'Name of employee'), $this->col('residential_status', 'Residential status'),
                $this->col('employee_type', 'Type of employee'), $this->col('basic_pay', 'Basic salary', true), $this->col('allowances', 'Allowances', true),
                $this->col('gross_pay', 'Gross pay', true), $this->col('nssf_employee', 'NSSF', true), $this->col('pension_employee', 'Pension', true),
                $this->col('shif', 'SHIF', true), $this->col('ahl_employee', 'Housing levy', true), $this->col('allowable_deductions', 'Allowable deductions', true),
                $this->col('taxable_pay', 'Chargeable pay', true), $this->col('tax_charged', 'Tax charged', true), $this->col('personal_relief', 'Personal relief', true),
                $this->col('insurance_relief', 'Insurance relief', true), $this->col('paye', 'PAYE', true),
            ], $lines->map(fn ($l) => $this->emp($l) + [
                'residential_status' => 'Resident', 'employee_type' => 'Primary Employee',
                'allowances' => round($l->gross_pay - $l->basic_pay, 2),
            ] + $l->only(['basic_pay', 'gross_pay', 'nssf_employee', 'pension_employee', 'shif', 'ahl_employee', 'allowable_deductions',
                'taxable_pay', 'tax_charged', 'personal_relief', 'insurance_relief', 'paye']))),
        };
    }

    // ---------------------------------------------------------------- payment files

    public function paymentFile(PayrollRun $run, string $type): array
    {
        $lines = $this->lines($run)->filter(fn ($l) => $l->payment_method === $type && $l->net_pay > 0);
        $ref = 'SAL'.$run->period->format('Ym').($run->type === 'off_cycle' ? 'R'.$run->id : '');

        if ($type === 'mpesa') {
            return $this->table('M-Pesa bulk payment', $this->slug($run, 'mpesa-bulk'), [
                $this->col('phone', 'Phone Number'), $this->col('amount', 'Amount', true), $this->col('employee_name', 'Name'),
                $this->col('national_id', 'ID Number'), $this->col('reference', 'Reference'),
            ], $lines->map(fn ($l) => $this->emp($l) + [
                'phone' => self::msisdn($l->payment_details['mpesa_phone'] ?? $l->employee?->phone), 'amount' => $l->net_pay, 'reference' => $ref,
            ]));
        }

        return $this->table('Bank transfer', $this->slug($run, 'bank-transfer'), [
            $this->col('employee_number', 'Employee No.'), $this->col('account_name', 'Account name'), $this->col('bank_name', 'Bank'),
            $this->col('bank_code', 'Bank code'), $this->col('bank_branch', 'Branch'), $this->col('branch_code', 'Branch code'),
            $this->col('account_number', 'Account number'), $this->col('amount', 'Amount', true), $this->col('reference', 'Reference'),
        ], $lines->map(fn ($l) => $this->emp($l) + [
            'account_name' => $l->payment_details['account_name'] ?? $l->employee?->full_name,
            'bank_name' => $l->payment_details['bank_name'] ?? null, 'bank_code' => $l->payment_details['bank_code'] ?? null,
            'bank_branch' => $l->payment_details['bank_branch'] ?? null, 'branch_code' => $l->payment_details['branch_code'] ?? null,
            'account_number' => $l->payment_details['account_number'] ?? null, 'amount' => $l->net_pay, 'reference' => $ref,
        ]));
    }

    /** 07XXXXXXXX / +2547XXXXXXXX → 2547XXXXXXXX */
    public static function msisdn(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }
        $d = preg_replace('/\D/', '', $phone);
        if (str_starts_with($d, '0')) {
            $d = '254'.substr($d, 1);
        } elseif (strlen($d) === 9) {
            $d = '254'.$d;
        }

        return $d;
    }

    // ---------------------------------------------------------------- GL journal

    public function journal(PayrollRun $run): array
    {
        $company = Company::find($run->company_id);
        $gl = PayrollSettings::get($company, 'payroll_gl');
        $lines = $this->lines($run);
        $items = PayrollRunItem::whereIn('payroll_run_employee_id', $lines->pluck('id'))->get();
        $entries = [];
        $add = function (string $account, string $desc, float $debit, float $credit) use (&$entries) {
            $key = $account.'|'.$desc;
            $entries[$key] ??= ['account' => $account, 'description' => $desc, 'debit' => 0.0, 'credit' => 0.0];
            $entries[$key]['debit'] = round($entries[$key]['debit'] + $debit, 2);
            $entries[$key]['credit'] = round($entries[$key]['credit'] + $credit, 2);
        };

        // Debits: earnings by GL code (unpaid-leave reductions reduce salaries expense)
        foreach ($items->where('category', 'earning')->groupBy(fn ($i) => $i->gl_account ?: $gl['salaries_expense']) as $acc => $g) {
            $add($acc, 'Salaries & wages', (float) $g->sum('amount'), 0);
        }
        // Employer contributions: expense and liability
        $add($gl['employer_nssf_expense'], 'Employer NSSF', $lines->sum('nssf_employer'), 0);
        $add($gl['employer_ahl_expense'], 'Employer Housing Levy', $lines->sum('ahl_employer'), 0);
        foreach ($items->where('category', 'employer')->whereNotIn('code', ['NSSF_ER', 'AHL_ER'])->groupBy(fn ($i) => $i->gl_account ?: $gl['salaries_expense']) as $acc => $g) {
            $add($acc, 'Employer pension & contributions', (float) $g->sum('amount'), 0);
        }
        // Reimbursements paid with salaries clear the claims liability (they are not a salary expense)
        $add($gl['reimbursements'], 'Expense reimbursements paid', (float) $lines->sum('reimbursements'), 0);
        // Credits: liabilities
        $add($gl['paye_payable'], 'PAYE payable', 0, $lines->sum('paye'));
        $add($gl['nssf_payable'], 'NSSF payable', 0, $lines->sum('nssf_employee') + $lines->sum('nssf_employer'));
        $add($gl['shif_payable'], 'SHIF payable', 0, $lines->sum('shif'));
        $add($gl['ahl_payable'], 'Housing Levy payable', 0, $lines->sum('ahl_employee') + $lines->sum('ahl_employer'));
        foreach ($items->where('category', 'deduction')->groupBy(fn ($i) => $i->gl_account ?: $gl['other_deductions']) as $acc => $g) {
            $add($acc, 'Deductions payable / recoveries', 0, (float) $g->sum('amount'));
        }
        foreach ($items->where('category', 'employer')->whereNotIn('code', ['NSSF_ER', 'AHL_ER'])->groupBy(fn ($i) => $i->gl_account ?: $gl['other_deductions']) as $acc => $g) {
            $add($acc.'-L', 'Employer contributions payable', 0, (float) $g->sum('amount'));
        }
        $add($gl['net_pay_payable'], 'Net salaries payable', 0, $lines->sum('net_pay'));

        $rows = collect($entries)->filter(fn ($e) => $e['debit'] != 0 || $e['credit'] != 0)
            ->map(fn ($e) => $e + ['date' => $run->pay_date?->toDateString(), 'reference' => 'PAYROLL-'.$run->period->format('Ym').'-'.$run->id])->values();

        return $this->table('GL journal', $this->slug($run, 'gl-journal'), [
            $this->col('date', 'Date'), $this->col('reference', 'Reference'), $this->col('account', 'GL account'),
            $this->col('description', 'Description'), $this->col('debit', 'Debit', true), $this->col('credit', 'Credit', true),
        ], $rows);
    }

    // ---------------------------------------------------------------- variance, YTD, trend

    public function previousRun(PayrollRun $run): ?PayrollRun
    {
        return PayrollRun::withoutGlobalScopes()->where('company_id', $run->company_id)->where('type', 'regular')
            ->whereDate('period', '<', $run->period?->toDateString() ?? now()->toDateString())->whereIn('status', ['calculated', 'reviewed', 'approved', 'paid'])
            ->orderByDesc('period')->first();
    }

    public static function pct(float $prev, float $curr): ?float
    {
        if ($prev == 0.0) {
            return $curr == 0.0 ? 0.0 : null;
        }

        return round(($curr - $prev) / abs($prev) * 100, 1);
    }

    public function variance(PayrollRun $current, ?PayrollRun $previous, float $threshold = 10): array
    {
        $curr = $this->lines($current)->keyBy('employee_id');
        $prev = $previous ? $this->lines($previous)->keyBy('employee_id') : collect();

        $rows = $curr->keys()->merge($prev->keys())->unique()->map(function ($id) use ($curr, $prev, $threshold) {
            $c = $curr->get($id);
            $p = $prev->get($id);
            $l = $c ?? $p;
            $pg = (float) ($p?->gross_pay ?? 0);
            $cg = (float) ($c?->gross_pay ?? 0);
            $pn = (float) ($p?->net_pay ?? 0);
            $cn = (float) ($c?->net_pay ?? 0);
            $pct = self::pct($pg, $cg);

            return $this->emp($l) + [
                'prev_gross' => $pg, 'curr_gross' => $cg, 'gross_variance' => round($cg - $pg, 2),
                'prev_net' => $pn, 'curr_net' => $cn, 'net_variance' => round($cn - $pn, 2),
                'variance_pct' => $pct,
                'status' => ! $p ? 'new' : (! $c ? 'dropped' : (($pct === null || abs($pct) > $threshold) ? 'flagged' : 'ok')),
            ];
        })->sortByDesc(fn ($r) => abs($r['gross_variance']))->values();

        $itemTotals = fn (?PayrollRun $r) => $r ? PayrollRunItem::whereIn('payroll_run_employee_id', PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $r->id)->select('id'))
            ->whereNotIn('category', ['tax', 'relief'])->selectRaw('code, category, MAX(name) as name, SUM(amount) as amount')->groupBy('code', 'category')->get()->keyBy('code') : collect();
        $ci = $itemTotals($current);
        $pi = $itemTotals($previous);
        $components = $ci->keys()->merge($pi->keys())->unique()->map(function ($code) use ($ci, $pi, $threshold) {
            $c = (float) ($ci->get($code)?->amount ?? 0);
            $p = (float) ($pi->get($code)?->amount ?? 0);
            $pct = self::pct($p, $c);

            return [
                'code' => $code, 'name' => preg_replace('/\s*\(.*$/', '', ($ci->get($code) ?? $pi->get($code))->name),
                'category' => ($ci->get($code) ?? $pi->get($code))->category,
                'previous' => round($p, 2), 'current' => round($c, 2), 'variance' => round($c - $p, 2), 'variance_pct' => $pct,
                'flagged' => $pct === null || abs($pct) > $threshold,
            ];
        })->sortBy('code')->values();

        return [
            'current' => ['id' => $current->id, 'title' => $current->title],
            'previous' => $previous ? ['id' => $previous->id, 'title' => $previous->title] : null,
            'threshold' => $threshold,
            'summary' => [
                'prev_gross' => round($prev->sum('gross_pay'), 2), 'curr_gross' => round($curr->sum('gross_pay'), 2),
                'prev_net' => round($prev->sum('net_pay'), 2), 'curr_net' => round($curr->sum('net_pay'), 2),
                'prev_headcount' => $prev->count(), 'curr_headcount' => $curr->count(),
                'flagged' => $rows->where('status', '!=', 'ok')->count(),
            ],
            'data' => $rows->all(),
            'components' => $components->all(),
        ];
    }

    public function varianceTable(array $v): array
    {
        return $this->table('Variance', 'variance-'.$v['current']['id'].'.csv', [
            $this->col('employee_number', 'Emp No.'), $this->col('employee_name', 'Employee'), $this->col('department', 'Department'),
            $this->col('prev_gross', 'Previous gross', true), $this->col('curr_gross', 'Current gross', true), $this->col('gross_variance', 'Gross variance', true),
            $this->col('prev_net', 'Previous net', true), $this->col('curr_net', 'Current net', true), $this->col('net_variance', 'Net variance', true),
            $this->col('variance_pct', 'Variance %'), $this->col('status', 'Status'),
        ], $v['data']);
    }

    public function ytd(int $companyId, int $year): array
    {
        $rows = PayrollRunEmployee::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereHas('run', fn ($q) => $q->whereYear('period', $year)->whereIn('status', ['approved', 'paid']))
            ->with(['employee:id,first_name,last_name,employee_number,kra_pin', 'department:id,name'])->get()
            ->groupBy('employee_id')->map(function ($g) {
                $l = $g->first();

                return $this->emp($l) + [
                    'months' => $g->pluck('payroll_run_id')->unique()->count(),
                    'gross_pay' => round($g->sum('gross_pay'), 2), 'taxable_pay' => round($g->sum('taxable_pay'), 2),
                    'paye' => round($g->sum('paye'), 2), 'nssf_employee' => round($g->sum('nssf_employee'), 2),
                    'shif' => round($g->sum('shif'), 2), 'ahl_employee' => round($g->sum('ahl_employee'), 2),
                    'net_pay' => round($g->sum('net_pay'), 2), 'employer_cost' => round($g->sum('employer_cost'), 2),
                ];
            })->sortBy('employee_number')->values();

        return $this->table("Year to date {$year}", "payroll-ytd-{$year}.csv", [
            $this->col('employee_number', 'Emp No.'), $this->col('employee_name', 'Employee'), $this->col('department', 'Department'),
            $this->col('months', 'Payslips'), $this->col('gross_pay', 'Gross', true), $this->col('taxable_pay', 'Taxable', true),
            $this->col('paye', 'PAYE', true), $this->col('nssf_employee', 'NSSF', true), $this->col('shif', 'SHIF', true),
            $this->col('ahl_employee', 'Housing levy', true), $this->col('net_pay', 'Net pay', true), $this->col('employer_cost', 'Employer cost', true),
        ], $rows);
    }

    /** Monthly totals of regular approved / paid runs (plus the current open run). */
    public function trend(int $companyId, int $months = 12): array
    {
        $from = now()->startOfMonth()->subMonths($months - 1);
        $runs = PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->whereDate('period', '>=', $from->toDateString())
            ->whereIn('status', ['calculated', 'reviewed', 'approved', 'paid'])->get()->groupBy(fn ($r) => $r->period->format('Y-m'));

        return collect(range($months - 1, 0))->map(function ($i) use ($runs) {
            $m = now()->startOfMonth()->subMonths($i);
            $g = $runs->get($m->format('Y-m'), collect());

            return [
                'period' => $m->format('Y-m'), 'label' => $m->format('M y'),
                'gross' => round($g->sum('total_gross'), 2), 'net' => round($g->sum('total_net'), 2),
                'paye' => round($g->sum('total_paye'), 2), 'employer_cost' => round($g->sum('total_employer_cost'), 2),
                'headcount' => (int) ($g->where('type', 'regular')->max('employee_count') ?? 0),
                'final' => $g->isNotEmpty() && $g->every(fn ($r) => in_array($r->status, ['approved', 'paid'], true)),
            ];
        })->values()->all();
    }

    public function monthOnMonth(int $companyId, int $months = 12): array
    {
        $trend = collect($this->trend($companyId, $months));
        $rows = $trend->map(function ($r, $i) use ($trend) {
            $p = $i > 0 ? $trend[$i - 1] : null;

            return $r + ['gross_change' => $p ? round($r['gross'] - $p['gross'], 2) : 0, 'gross_change_pct' => $p ? self::pct($p['gross'], $r['gross']) : null];
        });

        return $this->table('Month-on-month', 'payroll-month-on-month.csv', [
            $this->col('label', 'Month'), $this->col('headcount', 'Headcount'), $this->col('gross', 'Gross', true), $this->col('net', 'Net', true),
            $this->col('paye', 'PAYE', true), $this->col('employer_cost', 'Employer cost', true), $this->col('gross_change', 'Gross change', true),
            $this->col('gross_change_pct', 'Change %'),
        ], $rows);
    }

    // ---------------------------------------------------------------- annual / monthly statutory returns

    /**
     * Per-employee totals of approved / paid runs for a year (optionally one month):
     *  p9a / p10 / paye → KRA tax deduction card figures; nssf, shif, ahl, helb → contribution schedules.
     */
    public function annualReturn(int $companyId, int $year, string $type, ?int $month = null): array
    {
        $lines = PayrollRunEmployee::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereHas('run', fn ($q) => $q->whereYear('period', $year)->whereIn('status', ['approved', 'paid'])
                ->when($month, fn ($w) => $w->whereMonth('period', $month)))
            ->with(['employee:id,first_name,last_name,other_names,employee_number,kra_pin,nssf_number,shif_number,helb_number,national_id,phone', 'department:id,name', 'branch:id,name'])
            ->get();

        $sum = fn ($g, string $k) => round((float) $g->sum($k), 2);
        $rows = $lines->groupBy('employee_id')->map(function ($g) use ($sum) {
            $l = $g->first();

            return $this->emp($l) + [
                'nssf_number' => $l->employee?->nssf_number, 'shif_number' => $l->employee?->shif_number, 'helb_number' => $l->employee?->helb_number,
                'months' => $g->pluck('payroll_run_id')->unique()->count(),
                'basic_pay' => $sum($g, 'basic_pay'), 'gross_pay' => $sum($g, 'gross_pay'),
                'allowances' => round($sum($g, 'gross_pay') - $sum($g, 'basic_pay'), 2),
                'nssf_employee' => $sum($g, 'nssf_employee'), 'nssf_employer' => $sum($g, 'nssf_employer'),
                'nssf_tier1' => $sum($g, 'nssf_tier1'), 'nssf_tier2' => $sum($g, 'nssf_tier2'),
                'shif' => $sum($g, 'shif'), 'ahl_employee' => $sum($g, 'ahl_employee'), 'ahl_employer' => $sum($g, 'ahl_employer'),
                'pension_employee' => $sum($g, 'pension_employee'), 'allowable_deductions' => $sum($g, 'allowable_deductions'),
                'taxable_pay' => $sum($g, 'taxable_pay'), 'tax_charged' => $sum($g, 'tax_charged'),
                'personal_relief' => $sum($g, 'personal_relief'), 'insurance_relief' => $sum($g, 'insurance_relief'),
                'paye' => $sum($g, 'paye'), 'net_paye' => $sum($g, 'paye'), 'helb' => $sum($g, 'helb'),
                'nssf_total' => round($sum($g, 'nssf_employee') + $sum($g, 'nssf_employer'), 2),
                'ahl_total' => round($sum($g, 'ahl_employee') + $sum($g, 'ahl_employer'), 2),
            ];
        })->sortBy('employee_number')->values();

        $label = $month ? sprintf('%d-%02d', $year, $month) : (string) $year;
        $id = [$this->col('employee_number', 'Emp No.'), $this->col('employee_name', 'Employee'), $this->col('kra_pin', 'KRA PIN')];

        return match ($type) {
            'nssf' => $this->table("NSSF contributions {$label}", "nssf-{$label}.csv", [...$id, $this->col('nssf_number', 'NSSF No.'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('nssf_tier1', 'Tier I (employee)', true), $this->col('nssf_tier2', 'Tier II (employee)', true),
                $this->col('nssf_employee', 'Employee total', true), $this->col('nssf_employer', 'Employer total', true), $this->col('nssf_total', 'Total', true),
            ], $rows->filter(fn ($r) => $r['nssf_employee'] > 0)),
            'shif' => $this->table("SHIF contributions {$label}", "shif-{$label}.csv", [...$id, $this->col('shif_number', 'SHIF No.'),
                $this->col('gross_pay', 'Gross pay', true), $this->col('shif', 'Contribution', true),
            ], $rows->filter(fn ($r) => $r['shif'] > 0)),
            'ahl' => $this->table("Housing levy {$label}", "ahl-{$label}.csv", [...$id,
                $this->col('gross_pay', 'Gross pay', true), $this->col('ahl_employee', 'Employee', true), $this->col('ahl_employer', 'Employer', true), $this->col('ahl_total', 'Total', true),
            ], $rows->filter(fn ($r) => $r['ahl_employee'] > 0)),
            'helb' => $this->table("HELB deductions {$label}", "helb-{$label}.csv", [...$id, $this->col('helb_number', 'HELB No.'), $this->col('helb', 'Amount', true)],
                $rows->filter(fn ($r) => $r['helb'] > 0)),
            default => $this->table(($type === 'p9a' ? 'P9A tax deduction card' : 'P10 PAYE return')." {$label}", "{$type}-{$label}.csv", [...$id,
                $this->col('months', 'Months'), $this->col('basic_pay', 'Basic salary', true), $this->col('allowances', 'Allowances', true),
                $this->col('gross_pay', 'Gross pay', true), $this->col('nssf_employee', 'NSSF', true), $this->col('shif', 'SHIF', true),
                $this->col('ahl_employee', 'Housing levy', true), $this->col('pension_employee', 'Pension', true), $this->col('taxable_pay', 'Chargeable pay', true),
                $this->col('tax_charged', 'Tax charged', true), $this->col('personal_relief', 'Personal relief', true), $this->col('insurance_relief', 'Insurance relief', true),
                $this->col('paye', 'PAYE', true), $this->col('net_paye', 'Net PAYE', true),
            ], $rows),
        } + ['type' => $type, 'year' => $year, 'month' => $month];
    }

    // ---------------------------------------------------------------- CSV

    public function csv(array $table, bool $withTotals = false): StreamedResponse
    {
        return response()->streamDownload(function () use ($table, $withTotals) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($table['columns'], 'label'));
            foreach ($table['rows'] as $row) {
                fputcsv($out, array_map(function ($c) use ($row) {
                    $v = $row[$c['key']] ?? '';

                    return $c['money'] && $v !== '' && $v !== null ? number_format((float) $v, 2, '.', '') : $this->safe($v);
                }, $table['columns']));
            }
            if ($withTotals && $table['totals']) {
                fputcsv($out, array_map(fn ($c, $i) => $i === 0 ? 'TOTAL' : (isset($table['totals'][$c['key']]) ? number_format($table['totals'][$c['key']], 2, '.', '') : ''),
                    $table['columns'], array_keys($table['columns'])));
            }
            fclose($out);
        }, $table['filename'], ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Neutralise spreadsheet formula injection. */
    private function safe($v): string
    {
        $s = (string) $v;

        return $s !== '' && in_array($s[0], ['=', '+', '-', '@'], true) && ! is_numeric($s) ? "'".$s : $s;
    }
}
