<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Events\PayrollRunApproved;
use App\Modules\Payroll\Models\PayrollAdjustment;
use App\Modules\Payroll\Models\PayrollCalendar;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollLoanInstalment;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollRunItem;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Models\PayrollSalaryHistory;
use App\Modules\Payroll\Notifications\PayrollRunDecision;
use App\Modules\Payroll\Notifications\PayrollRunAwaitingApproval;
use App\Modules\Payroll\Notifications\PayslipAvailable;
use App\Modules\Payroll\Services\Calculation\CalculationInput;
use App\Modules\Payroll\Services\Calculation\PayrollCalculator;
use App\Modules\Payroll\Support\PayrollDefaults;
use App\Modules\Payroll\Support\PayrollSettings;
use App\Modules\Payroll\Support\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollRunService
{
    public function __construct(
        private PayrollCalculator $calculator,
        private StatutoryRateRepository $rates,
        private PayrollInputCollector $inputs,
    ) {}

    // ------------------------------------------------------------------ creation

    public function create(Company $company, array $data, User $actor): PayrollRun
    {
        $period = CarbonImmutable::parse(strlen($data['period']) === 7 ? $data['period'].'-01' : $data['period'])->startOfMonth();
        $type = $data['type'] ?? 'regular';
        $reason = $type === 'off_cycle' ? ($data['off_cycle_reason'] ?? 'bonus') : null;

        if ($type === 'regular' && PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('type', 'regular')->whereDate('period', $period->toDateString())->exists()) {
            throw ValidationException::withMessages(['period' => 'A regular payroll run already exists for '.$period->format('F Y').'. Use an off-cycle run for corrections.']);
        }

        $options = [];
        $exitEmployeeId = null;
        if ($reason === 'final_dues') {
            $employee = Employee::withoutGlobalScope('company')->where('company_id', $company->id)->findOrFail($data['employee_id']);
            $exitEmployeeId = $employee->id;
            $options = [
                'notice_pay' => (float) ($data['notice_pay'] ?? 0),
                'leave_days' => isset($data['leave_days']) && $data['leave_days'] !== '' ? (float) $data['leave_days'] : null,
                'exit_date' => $data['exit_date'] ?? $this->finalDuesDate($employee)?->toDateString(),
            ];
        } elseif ($type === 'off_cycle') {
            $options['employee_ids'] = array_values(array_map('intval', $data['employee_ids'] ?? []));
        }

        $payDay = (int) PayrollSettings::get($company, 'pay_day');
        $payDate = $data['pay_date'] ?? $this->calendarRow($company, $period)?->pay_date?->toDateString()
            ?? $period->day(min($payDay, $period->daysInMonth))->toDateString();

        $label = $type === 'regular' ? $period->format('F Y').' payroll'
            : $period->format('F Y').' · '.(PayrollRun::OFF_CYCLE_REASONS[$reason] ?? 'Off-cycle')
                .($exitEmployeeId ? ' – '.Employee::withoutGlobalScope('company')->find($exitEmployeeId)?->full_name : '');

        $run = activity()->withoutLogs(fn () => PayrollRun::create([
            'company_id' => $company->id,
            'title' => $data['title'] ?? $label,
            'period' => $period->toDateString(),
            'period_start' => $period->toDateString(),
            'period_end' => $period->endOfMonth()->toDateString(),
            'pay_date' => $payDate,
            'type' => $type,
            'off_cycle_reason' => $reason,
            'exit_employee_id' => $exitEmployeeId,
            'options' => $options ?: null,
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'created_by' => $actor->id,
        ]));

        activity('payroll')->performedOn($run)->causedBy($actor)->withProperties(['type' => $type, 'reason' => $reason])->log('Payroll run created');
        if ($exitEmployeeId) {
            // The leaver's leave settlement moves from the open regular run to this final-dues run.
            self::inputsChanged($company->id, [$exitEmployeeId]);
        }
        $this->calendarRow($company, $period)?->update(['status' => 'processing']);

        return $run;
    }

    public function calendarRow(Company $company, CarbonImmutable|\DateTimeInterface $period): ?PayrollCalendar
    {
        return PayrollCalendar::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereDate('period', CarbonImmutable::parse($period)->startOfMonth()->toDateString())->first();
    }

    // ------------------------------------------------------------------ eligibility

    public function employmentEnd(Employee $employee): ?CarbonImmutable
    {
        if ($employee->status === 'exited') {
            $exit = $employee->exitRecord()->first();

            return $exit?->exit_date ? CarbonImmutable::parse($exit->exit_date) : null;
        }
        if ($employee->status === 'transferred') {
            $next = Employee::withoutGlobalScopes()->where('previous_employee_id', $employee->id)->orderBy('id')->first();

            return $next?->date_of_joining ? CarbonImmutable::parse($next->date_of_joining)->subDay() : null;
        }

        return null;
    }

    /**
     * First day this record is paid. Normally the joining date, but when an older record of the same person
     * (re-instatement / transfer: previous_employee_id) was still employed on or after that date, pay starts the day
     * after the predecessor's last day so the overlapping days are never paid twice.
     */
    public function employmentStart(Employee $employee): ?CarbonImmutable
    {
        $start = $employee->date_of_joining ? CarbonImmutable::parse($employee->date_of_joining)->startOfDay() : null;
        if (! $employee->previous_employee_id) {
            return $start;
        }
        $previous = Employee::withoutGlobalScopes()->find($employee->previous_employee_id);
        $previousEnd = $previous ? $this->employmentEnd($previous) : null;
        if ($previousEnd && (! $start || $previousEnd->gte($start))) {
            return $previousEnd->addDay()->startOfDay();
        }

        return $start;
    }

    /** Last day for final dues: the recorded end of employment, else a scheduled (future) exit date. */
    public function finalDuesDate(Employee $employee): ?CarbonImmutable
    {
        if ($end = $this->employmentEnd($employee)) {
            return $end;
        }
        $exit = $employee->exitRecord()->first();

        return $exit && $exit->status === 'scheduled' && $exit->exit_date ? CarbonImmutable::parse($exit->exit_date) : null;
    }

    /** @return Collection<int, Employee> */
    public function eligibleEmployees(PayrollRun $run): Collection
    {
        $start = $run->period_start->toDateString();
        $profiles = PayrollPayProfile::withoutGlobalScopes()->where('company_id', $run->company_id);
        $base = Employee::withoutGlobalScope('company')->where('company_id', $run->company_id)
            ->with(['exitRecord'])
            ->whereIn('id', (clone $profiles)->select('employee_id'));
        $held = (clone $profiles)->where('on_hold', true)->pluck('employee_id')->flip();

        // A leaver's profile goes on hold at exit. The hold only excludes them from runs whose period starts after
        // their last day: the run covering the exit month still pays them (prorated) and final dues are always allowed.
        $onPayroll = function (Employee $e) use ($held, $start): bool {
            if (! in_array($e->status, ['exited', 'transferred'], true)) {
                return ! $held->has($e->id);
            }
            $endDate = $this->employmentEnd($e);

            return $endDate && $endDate->toDateString() >= $start;
        };

        if ($run->off_cycle_reason === 'final_dues') {
            return $base->whereKey($run->exit_employee_id)->get();
        }
        if ($run->type === 'off_cycle') {
            $ids = collect($run->options['employee_ids'] ?? [])
                ->merge(PayrollAdjustment::where('payroll_run_id', $run->id)->pluck('employee_id'))->unique()->all();

            return $base->whereIn('id', $ids)->get()->filter($onPayroll)->values();
        }

        $end = $run->period_end->toDateString();
        // Leavers whose final-dues run for this month already paid the month's salary are not paid twice.
        $paidInFinalDues = PayrollRun::withoutGlobalScopes()->where('company_id', $run->company_id)->where('off_cycle_reason', 'final_dues')
            ->whereDate('period', $run->period->toDateString())->whereNotNull('exit_employee_id')->get()
            ->filter(fn (PayrollRun $r) => ! empty($r->options['includes_salary']))->pluck('exit_employee_id')->flip();

        return $base->where(fn ($q) => $q->whereNull('date_of_joining')->orWhereDate('date_of_joining', '<=', $end))
            ->whereIn('status', ['active', 'suspended', 'exited', 'transferred'])
            ->get()
            ->filter(fn (Employee $e) => $onPayroll($e) && ! $paidInFinalDues->has($e->id)
                && ! (($from = $this->employmentStart($e)) && $from->toDateString() > $end))
            ->values();
    }

    /**
     * Employees a one-off item can be entered for: everyone the run pays (regular / final dues), or for other off-cycle
     * runs anyone on the payroll in the period, including leavers whose last day falls in or after it.
     *
     * @return Collection<int, Employee>
     */
    public function payableEmployees(PayrollRun $run): Collection
    {
        if ($run->type === 'regular' || $run->off_cycle_reason === 'final_dues') {
            return $this->eligibleEmployees($run);
        }
        $start = $run->period_start->toDateString();
        $profiles = PayrollPayProfile::withoutGlobalScopes()->where('company_id', $run->company_id);
        $held = (clone $profiles)->where('on_hold', true)->pluck('employee_id')->flip();

        return Employee::withoutGlobalScope('company')->where('company_id', $run->company_id)->with('exitRecord')
            ->whereIn('id', (clone $profiles)->select('employee_id'))
            ->whereIn('status', ['active', 'suspended', 'exited', 'transferred'])->get()
            ->filter(function (Employee $e) use ($held, $start) {
                if (! in_array($e->status, ['exited', 'transferred'], true)) {
                    return ! $held->has($e->id);
                }
                $end = $this->employmentEnd($e);

                return $end && $end->toDateString() >= $start;
            })->values();
    }

    public function basicSalaryOn(Employee $employee, PayrollPayProfile $profile, string $date): float
    {
        $h = PayrollSalaryHistory::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereDate('effective_date', '<=', $date)->orderByDesc('effective_date')->orderByDesc('id')->first();

        return (float) ($h?->basic_salary ?? $profile->basic_salary);
    }

    // ------------------------------------------------------------------ calculation

    public function calculate(PayrollRun $run, User $actor): PayrollRun
    {
        if (! $run->isEditable()) {
            throw ValidationException::withMessages(['status' => 'An approved run is locked. Use an off-cycle run for corrections.']);
        }
        $company = Company::findOrFail($run->company_id);
        PayrollDefaults::ensureComponents($company->id);

        $rates = $this->rates->forDate($run->period_end);
        $periodStart = CarbonImmutable::parse($run->period_start);
        $periodEnd = CarbonImmutable::parse($run->period_end);
        $workingDays = max(1, WorkingDays::between($periodStart, $periodEnd, $company->id));
        $hoursPerDay = (float) $company->setting('hours_per_day', 8);
        $monthlyHours = (float) (PayrollSettings::get($company, 'overtime_monthly_hours') ?: ($hoursPerDay * $workingDays ?: 225));
        $components = PayrollComponent::withoutGlobalScopes()->where('company_id', $company->id)->get();
        $byRole = $components->where('active', true)->keyBy('role');
        $byCode = $components->keyBy('code');
        $isFinal = $run->off_cycle_reason === 'final_dues';
        $isRegular = $run->type === 'regular';

        $employees = $this->eligibleEmployees($run);
        $profiles = PayrollPayProfile::withoutGlobalScopes()->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');
        $adjustments = PayrollAdjustment::with('component')->where('payroll_run_id', $run->id)->get()->groupBy('employee_id');
        // Leavers with their own final-dues run get the leave settlement there, not in the regular run.
        $hasFinalDuesRun = $isRegular ? PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)->where('off_cycle_reason', 'final_dues')
            ->whereIn('exit_employee_id', $employees->pluck('id'))->pluck('exit_employee_id')->flip() : collect();

        DB::transaction(function () use ($run, $actor, $company, $rates, $periodStart, $periodEnd, $workingDays, $monthlyHours, $byRole, $byCode, $isFinal, $isRegular, $employees, $profiles, $adjustments, $hasFinalDuesRun) {
            PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)->delete(); // items cascade

            $totals = ['gross' => 0, 'deductions' => 0, 'net' => 0, 'paye' => 0, 'cost' => 0, 'exceptions' => 0, 'count' => 0];
            foreach ($employees as $employee) {
                $profile = $profiles[$employee->id] ?? null;
                if (! $profile) {
                    continue;
                }
                // A scheduled exit inside the period also ends pay (and triggers the leave settlement) in this run.
                $endDate = $isFinal && ! empty($run->options['exit_date'])
                    ? CarbonImmutable::parse($run->options['exit_date']) : $this->finalDuesDate($employee);
                $startDate = $this->employmentStart($employee);
                $basic = $this->basicSalaryOn($employee, $profile, $periodEnd->toDateString());
                // Proration basis: working days (see PayrollCalculator), counted with the company's holidays.
                $employedWorkingDays = null;
                if (($startDate && $startDate->gt($periodStart)) || ($endDate && $endDate->lt($periodEnd))) {
                    $from = $startDate && $startDate->gt($periodStart) ? $startDate : $periodStart;
                    $to = $endDate && $endDate->lt($periodEnd) ? $endDate : $periodEnd;
                    $employedWorkingDays = $to->lt($from) ? 0.0 : (float) WorkingDays::between($from, $to, $company->id);
                }

                // Prior pay this month (off-cycle runs are cumulative with locked runs for tax & caps)
                $priorLines = $isRegular ? collect() : PayrollRunEmployee::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)->where('payroll_run_id', '!=', $run->id)
                    ->whereHas('run', fn ($q) => $q->whereDate('period', $run->period->toDateString())->whereIn('status', ['approved', 'paid']))->get();
                $regularDone = ! $isRegular && PayrollRunEmployee::withoutGlobalScopes()->where('employee_id', $employee->id)
                    ->whereHas('run', fn ($q) => $q->where('type', 'regular')->whereDate('period', $run->period->toDateString()))->exists();

                $includeSalary = $isRegular || ($isFinal && ! $regularDone);
                if ($isFinal) {
                    // Remembered so the regular run for the same month does not pay the leaver's salary again.
                    $run->options = array_merge($run->options ?? [], ['includes_salary' => $includeSalary]);
                }
                $lines = [];
                if ($includeSalary) {
                    $lines = PayrollEmployeeComponent::withoutGlobalScopes()->with('component')->where('employee_id', $employee->id)
                        ->activeBetween($periodStart->toDateString(), $periodEnd->toDateString())->get()
                        ->filter(fn ($ec) => $ec->component && $ec->component->active)
                        ->map(fn ($ec) => $ec->component->toEngine($ec->amount, $ec->rate))->values()->all();
                }
                foreach ($adjustments[$employee->id] ?? [] as $adj) {
                    $c = $adj->component->toEngine((float) $adj->amount, null, 'one_off', $adj->id);
                    $c['calculation'] = 'fixed';
                    $c['name'] = $adj->description ? $adj->component->name.' – '.$adj->description : $adj->component->name;
                    $lines[] = $c;
                }

                $inputs = ($isRegular || $isFinal) ? $this->inputs->collect($employee, $periodStart, $periodEnd) : [];
                // Unexplained absences (Attendance): flagged for review by default, deducted like unpaid leave when the
                // company chooses, ignored when it does not track attendance.
                $absentDays = (float) ($inputs['days_absent'] ?? 0);
                $absencePolicy = (string) PayrollSettings::get($company, 'absence_policy');
                if ($absentDays > 0 && $absencePolicy === 'deduct') {
                    $inputs['unpaid_leave_days'] = (float) ($inputs['unpaid_leave_days'] ?? 0) + $absentDays;
                }

                // Leave accrued to the exit date, net of leave taken (Leave module): positive → encash, still negative
                // after full accrual → recover the over-taken days. Paid in the final-dues run (HR's override wins), or in
                // the regular run covering the exit date when the leaver has no final-dues run.
                $settleInRegular = $isRegular && $endDate && $endDate->betweenIncluded($periodStart, $periodEnd)
                    && ! $hasFinalDuesRun->has($employee->id) && isset($inputs['annual_leave_settlement']);
                if ($isFinal || $settleInRegular) {
                    $leaveDays = $isFinal
                        ? (float) ($run->options['leave_days'] ?? ($this->settledInRegularRun($employee, $run) ? 0 : ($inputs['annual_leave_settlement'] ?? $inputs['annual_leave_balance'] ?? 0)))
                        : (float) $inputs['annual_leave_settlement'];
                    array_push($lines, ...$this->leaveSettlementLines($leaveDays, $basic, $company, $byCode, $byRole));
                }
                if ($isFinal) {
                    if (($notice = (float) ($run->options['notice_pay'] ?? 0)) > 0 && ($comp = $byCode['NOTICE_PAY'] ?? $byRole['notice_pay'] ?? null)) {
                        $c = $comp->toEngine($notice, null, 'one_off');
                        $c['calculation'] = 'fixed';
                        $lines[] = $c;
                    }
                }
                foreach (['overtime', 'pension_employee', 'pension_employer'] as $role) {
                    if ($comp = $byRole[$role] ?? null) {
                        $lines[] = $comp->toEngine(0) + ['meta_only' => true];
                    }
                }

                $deductions = ($isRegular || $isFinal) ? $this->loanAndAdvanceDeductions($employee, $run, $isFinal, $byRole) : [];

                $input = CalculationInput::make([
                    'basicSalary' => $basic,
                    'periodStart' => $periodStart,
                    'periodEnd' => $periodEnd,
                    'employmentStart' => $startDate,
                    'employmentEnd' => $endDate,
                    'workingDays' => $workingDays,
                    'employedWorkingDays' => $employedWorkingDays,
                    'monthlyHours' => $monthlyHours,
                    'overtimeMultiplierNormal' => (float) PayrollSettings::get($company, 'overtime_multiplier_normal'),
                    'overtimeMultiplierHoliday' => (float) PayrollSettings::get($company, 'overtime_multiplier_holiday'),
                    'inputs' => $inputs,
                    'components' => $lines,
                    'deductions' => $deductions,
                    'pensionEmployeePercent' => $includeSalary ? (float) $profile->pension_employee_percent : 0,
                    'pensionEmployerPercent' => $includeSalary ? (float) $profile->pension_employer_percent : 0,
                    'disabilityExempt' => (bool) $profile->disability_exempt,
                    'includeBasic' => $includeSalary,
                    'prior' => $this->priorFrom($priorLines),
                ]);

                try {
                    $r = $this->calculator->calculate($input, $rates);
                } catch (\Throwable $e) {
                    throw ValidationException::withMessages(['calculation' => "{$employee->full_name}: {$e->getMessage()}"]);
                }

                $flags = $r['flags'];
                if (! $profile->hasPaymentDetails()) {
                    $flags[] = ['code' => 'missing_payment_details', 'severity' => 'warning', 'message' => 'Payment details are incomplete for '.$profile->payment_method.'.'];
                }
                if (blank($employee->kra_pin)) {
                    $flags[] = ['code' => 'missing_kra_pin', 'severity' => 'warning', 'message' => 'Employee has no KRA PIN.'];
                }
                if ($includeSalary && $basic <= 0) {
                    $flags[] = ['code' => 'zero_basic', 'severity' => 'error', 'message' => 'Basic salary is zero.'];
                }
                if ($absentDays > 0 && $absencePolicy !== 'ignore') {
                    $flags[] = ['code' => 'unexplained_absence', 'severity' => 'warning', 'message' => rtrim(rtrim(number_format($absentDays, 1), '0'), '.')
                        .' unexplained absence day(s) '.($absencePolicy === 'deduct' ? 'deducted as unpaid.' : 'not deducted. Approve leave, correct the punches, or switch the absence policy to deduct.')];
                }

                $line = PayrollRunEmployee::create([
                    'company_id' => $run->company_id,
                    'payroll_run_id' => $run->id,
                    'employee_id' => $employee->id,
                    'department_id' => $employee->department_id,
                    'branch_id' => $employee->branch_id,
                    ...collect($r)->only([
                        'basic_salary', 'basic_pay', 'days_in_period', 'days_worked', 'working_days', 'unpaid_days', 'half_pay_days',
                        'overtime_hours', 'overtime_pay', 'gross_pay', 'non_taxable_pay', 'nssf_employee', 'nssf_tier1', 'nssf_tier2',
                        'nssf_employer', 'shif', 'ahl_employee', 'ahl_employer', 'pension_employee', 'pension_employer',
                        'allowable_deductions', 'taxable_pay', 'tax_charged', 'personal_relief', 'insurance_relief', 'paye', 'helb',
                        'other_deductions', 'reimbursements', 'total_deductions', 'net_pay', 'employer_cost',
                    ])->all(),
                    'payment_method' => $profile->payment_method,
                    'payment_details' => $profile->paymentDetails(),
                    'inputs' => $inputs ?: null,
                    'flags' => $flags ?: null,
                ]);
                PayrollRunItem::insert(collect($r['items'])->map(fn ($i) => [
                    'payroll_run_employee_id' => $line->id, 'component_id' => $i['component_id'], 'code' => $i['code'],
                    'name' => mb_substr($i['name'], 0, 250), 'category' => $i['category'], 'amount' => $i['amount'], 'quantity' => $i['quantity'],
                    'taxable' => $i['taxable'], 'source' => $i['source'], 'source_id' => $i['source_id'],
                    'gl_account' => $i['gl_account'], 'sort_order' => $i['sort_order'],
                ])->all());

                $totals['count']++;
                $totals['gross'] += $r['gross_pay'];
                $totals['deductions'] += $r['total_deductions'];
                $totals['net'] += $r['net_pay'];
                $totals['paye'] += $r['paye'];
                $totals['cost'] += $r['employer_cost'];
                $totals['exceptions'] += $flags ? 1 : 0;
            }

            $this->quietly($run, [
                'options' => $run->options,
                'status' => 'calculated',
                'needs_recalculation' => false,
                'employee_count' => $totals['count'],
                'total_gross' => round($totals['gross'], 2),
                'total_deductions' => round($totals['deductions'], 2),
                'total_net' => round($totals['net'], 2),
                'total_paye' => round($totals['paye'], 2),
                'total_employer_cost' => round($totals['cost'], 2),
                'exception_count' => $totals['exceptions'],
                'calculated_by' => $actor->id,
                'calculated_at' => now(),
                'reviewed_by' => null,
                'reviewed_at' => null,
            ]);
        });

        activity('payroll')->performedOn($run)->causedBy($actor)
            ->withProperties(['employees' => $run->employee_count, 'gross' => $run->total_gross, 'net' => $run->total_net])->log('Payroll calculated');

        return $run->fresh();
    }

    /** Encashment (+ days) or recovery (− days) of annual leave at basic ÷ the company's encashment divisor. */
    private function leaveSettlementLines(float $leaveDays, float $basic, Company $company, Collection $byCode, Collection $byRole): array
    {
        $divisor = (float) PayrollSettings::get($company, 'leave_encashment_divisor') ?: 22;
        $daysLabel = rtrim(rtrim(number_format(abs($leaveDays), 2), '0'), '.');
        $comp = $leaveDays > 0 ? ($byCode['LEAVE_ENC'] ?? $byRole['leave_encashment'] ?? null)
            : ($leaveDays < 0 ? ($byCode['LEAVE_REC'] ?? $byRole['leave_recovery'] ?? null) : null);
        if (! $comp) {
            return [];
        }
        $c = $comp->toEngine(round($basic / $divisor * abs($leaveDays), 2), null, 'leave_settlement');
        $c['calculation'] = 'fixed';
        $c['name'] = sprintf($leaveDays > 0 ? 'Leave encashment (%s days)' : 'Leave over-taken recovery (%s days)', $daysLabel);

        return [$c];
    }

    /** True when an approved regular run already paid this leaver's leave settlement. */
    private function settledInRegularRun(Employee $employee, PayrollRun $run): bool
    {
        return PayrollRunItem::where('source', 'leave_settlement')
            ->whereIn('payroll_run_employee_id', PayrollRunEmployee::withoutGlobalScopes()->where('employee_id', $employee->id)
                ->whereHas('run', fn ($q) => $q->where('type', 'regular')->whereIn('status', ['approved', 'paid'])->where('id', '!=', $run->id))->select('id'))
            ->exists();
    }

    private function priorFrom(Collection $lines): array
    {
        if ($lines->isEmpty()) {
            return [];
        }

        return [
            'gross' => $lines->sum('gross_pay'),
            'taxable_pay' => $lines->sum('taxable_pay'),
            'tax_charged' => $lines->sum('tax_charged'),
            'reliefs' => $lines->sum(fn ($l) => $l->personal_relief + $l->insurance_relief),
            'paye' => $lines->sum('paye'),
            'nssf_tier1' => $lines->sum('nssf_tier1'),
            'nssf_tier2' => $lines->sum('nssf_tier2'),
            'shif' => $lines->sum('shif'),
            'ahl_employee' => $lines->sum('ahl_employee'),
            'pension_allowable' => $lines->sum('pension_employee'),
        ];
    }

    /** Loan instalments due (one per loan per run) and approved salary advances; final dues take the full outstanding balance. */
    private function loanAndAdvanceDeductions(Employee $employee, PayrollRun $run, bool $final, Collection $byRole): array
    {
        $out = [];
        $loanComp = $byRole['loan'] ?? null;
        $advComp = $byRole['advance'] ?? null;
        $loans = PayrollLoan::withoutGlobalScopes()->where('employee_id', $employee->id)
            ->whereIn('status', $final ? ['active', 'paused'] : ['active'])->get();

        foreach ($loans as $loan) {
            if ($final) {
                $outstanding = $loan->remainingRepayments() ?: (float) $loan->balance;
                if ($outstanding > 0) {
                    $out[] = ['code' => $loanComp?->code ?? 'LOAN', 'name' => "Loan settlement ({$loan->reference})", 'amount' => $outstanding,
                        'source' => 'loan_settlement', 'source_id' => $loan->id, 'gl_account' => $loanComp?->gl_account, 'component_id' => $loanComp?->id];
                }

                continue;
            }
            $inst = PayrollLoanInstalment::where('payroll_loan_id', $loan->id)->where('status', 'pending')
                ->whereDate('period', '<=', $run->period->toDateString())->orderBy('number')->first();
            if ($inst) {
                $out[] = ['code' => $loanComp?->code ?? 'LOAN', 'name' => "Loan repayment ({$loan->reference}) {$inst->number}/{$loan->instalments}"
                    .($inst->interest > 0 ? sprintf(' – principal %s + interest %s', number_format($inst->principal, 2), number_format($inst->interest, 2)) : ''),
                    'amount' => $inst->amount, 'source' => 'loan', 'source_id' => $inst->id, 'gl_account' => $loanComp?->gl_account, 'component_id' => $loanComp?->id];
            }
        }

        $advances = PayrollSalaryAdvance::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', 'approved')
            ->when(! $final, fn ($q) => $q->where(fn ($w) => $w->whereNull('recovery_period')->orWhereDate('recovery_period', '<=', $run->period->toDateString())))
            ->get();
        foreach ($advances as $adv) {
            // Equal monthly instalments over repayment_months; final dues recover whatever is still outstanding.
            $months = max(1, (int) ($adv->repayment_months ?: 1));
            $amount = $final ? $adv->remaining() : $adv->nextInstalment();
            if ($amount <= 0) {
                continue;
            }
            $label = $final ? 'Salary advance recovery (balance)'
                : ($months > 1 ? sprintf('Salary advance recovery %d/%d', min($months, $this->advanceInstalmentNumber($adv)), $months) : 'Salary advance recovery');
            $out[] = ['code' => $advComp?->code ?? 'ADVANCE', 'name' => $label, 'amount' => $amount,
                'source' => 'advance', 'source_id' => $adv->id, 'gl_account' => $advComp?->gl_account, 'component_id' => $advComp?->id];
        }

        return $out;
    }

    private function advanceInstalmentNumber(PayrollSalaryAdvance $adv): int
    {
        $share = round($adv->amount / max(1, (int) $adv->repayment_months), 2);

        return $share > 0 ? (int) floor(((float) $adv->amount_recovered + 0.005) / $share) + 1 : 1;
    }

    // ------------------------------------------------------------------ workflow

    public function review(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== 'calculated' || $run->needs_recalculation) {
            throw ValidationException::withMessages(['status' => $run->needs_recalculation
                ? 'Inputs changed since the last calculation. Recalculate before review.' : 'Only a calculated run can be marked as reviewed.']);
        }
        abort_if($this->ownPayBlocked($run, $actor), 403, self::OWN_PAY_MESSAGE);
        $this->assertNoErrorFlags($run, 'reviewed');
        if (! $this->canReview($run, $actor)) {
            abort(403, 'Segregation of duties: the person who calculated a run cannot review it. Ask HR (payroll.review) or another payroll officer to review.');
        }
        $this->logOwnPaySignoff($run, $actor, 'reviewed');
        $this->quietly($run, ['status' => 'reviewed', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        activity('payroll')->performedOn($run)->causedBy($actor)->log('Payroll reviewed');

        $approvers = User::where('company_id', $run->company_id)->where('is_active', true)
            ->permission('payroll.approve')->whereNotIn('id', array_filter([$actor->id, $run->created_by, $run->calculated_by]))->get();
        foreach ($approvers as $u) {
            $u->notify(new PayrollRunAwaitingApproval($run));
        }

        return $run->fresh();
    }

    /** Reviewer holds payroll.review (HR) or payroll.manage, and must not be the person who calculated the run. */
    public function canReview(PayrollRun $run, User $user): bool
    {
        return ($user->can('payroll.review') || $user->can('payroll.manage')) && (int) $run->calculated_by !== (int) $user->id
            && ! $this->ownPayBlocked($run, $user);
    }

    public const OWN_PAY_MESSAGE = 'Conflict of interest: this run includes your own salary, so you cannot review, approve or mark it paid. Ask another authorised user.';

    private array $paysUserCache = [];

    /** True when one of the user's employee records has a line in the run (nobody signs off their own pay). */
    public function paysUser(PayrollRun $run, User $user): bool
    {
        return $this->paysUserCache[$run->id.'-'.$user->id] ??= PayrollRunEmployee::withoutGlobalScopes()
            ->where('payroll_run_id', $run->id)
            ->whereIn('employee_id', Employee::withoutGlobalScopes()->where('user_id', $user->id)->select('id'))
            ->exists();
    }

    /**
     * Own pay blocks sign-off unless the company has switched on `own_pay_signoff` (small companies where every
     * reviewer/approver is also an employee on the payroll). When it is on, the sign-off is still logged.
     */
    public function ownPayBlocked(PayrollRun $run, User $user): bool
    {
        return $this->paysUser($run, $user) && ! PayrollSettings::get(Company::find($run->company_id), 'own_pay_signoff');
    }

    private function logOwnPaySignoff(PayrollRun $run, User $actor, string $step): void
    {
        if ($this->paysUser($run, $actor)) {
            activity('payroll')->performedOn($run)->causedBy($actor)->withProperties(['own_pay_signoff' => true, 'step' => $step])
                ->log("Payroll {$step} by a user paid in this run (company allows own-pay sign-off)");
        }
    }

    /** A run with an unresolved error flag (e.g. zero basic, negative net) cannot be signed off. */
    private function assertNoErrorFlags(PayrollRun $run, string $step): void
    {
        $count = PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)->get(['flags'])
            ->filter(fn ($l) => collect($l->flags ?? [])->contains(fn ($f) => ($f['severity'] ?? null) === 'error'))->count();
        if ($count > 0) {
            throw ValidationException::withMessages(['status' => "{$count} employee line(s) have errors (e.g. zero basic salary or negative net pay). Fix them and recalculate before the run can be {$step}."]);
        }
    }

    public function canMarkPaid(PayrollRun $run, User $user): bool
    {
        return $user->can('payroll.approve') && ! $this->ownPayBlocked($run, $user);
    }

    public function reopen(PayrollRun $run, User $actor, ?string $reason = null): PayrollRun
    {
        if ($run->status !== 'reviewed') {
            throw ValidationException::withMessages(['status' => 'Only a reviewed run can be sent back.']);
        }
        $this->quietly($run, ['status' => 'calculated', 'reviewed_by' => null, 'reviewed_at' => null]);
        activity('payroll')->performedOn($run)->causedBy($actor)->withProperties(['reason' => $reason])->log('Payroll returned for changes');

        return $run->fresh();
    }

    public function canApprove(PayrollRun $run, User $user): bool
    {
        return $user->can('payroll.approve')
            && ! in_array($user->id, array_filter([$run->created_by, $run->calculated_by, $run->reviewed_by]), true)
            && ! $this->ownPayBlocked($run, $user);
    }

    public function approve(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== 'reviewed') {
            throw ValidationException::withMessages(['status' => 'Only a reviewed run can be approved.']);
        }
        abort_if($this->ownPayBlocked($run, $actor), 403, self::OWN_PAY_MESSAGE);
        $this->assertNoErrorFlags($run, 'approved');
        if (! $this->canApprove($run, $actor)) {
            abort(403, 'Segregation of duties: a run must be approved by someone other than the person who prepared, calculated or reviewed it.');
        }

        // Never pay out a negative net salary: fix deductions (loans, advances, adjustments) and recalculate first.
        $negative = PayrollRunEmployee::withoutGlobalScopes()->with('employee:id,first_name,last_name')
            ->where('payroll_run_id', $run->id)->where('net_pay', '<', 0)->get();
        if ($negative->isNotEmpty()) {
            $names = $negative->take(5)->map(fn ($l) => $l->employee?->full_name ?? "line #{$l->id}")->implode(', ');
            throw ValidationException::withMessages(['status' => "Cannot approve: {$negative->count()} employee(s) have negative net pay ({$names}"
                .($negative->count() > 5 ? ', …' : '').'). Reduce their deductions or pause loan/advance recovery, then return the run for recalculation.']);
        }

        $this->logOwnPaySignoff($run, $actor, 'approved');
        DB::transaction(function () use ($run, $actor) {
            $this->quietly($run, ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->postDeductions($run);
            $this->calendarRow(Company::find($run->company_id), $run->period)?->update(['status' => 'approved']);
        });
        activity('payroll')->performedOn($run)->causedBy($actor)
            ->withProperties(['gross' => $run->total_gross, 'net' => $run->total_net])->log('Payroll approved');

        // In-app payslip notices go through the queue (PayslipAvailable is ShouldQueue), so approval stays fast for large runs.
        PayrollRunEmployee::withoutGlobalScopes()->with('employee.user')->where('payroll_run_id', $run->id)
            ->chunkById(200, function ($lines) {
                foreach ($lines as $line) {
                    $line->employee?->user?->notify(new PayslipAvailable($line, withPdf: false));
                }
            });
        if ($preparer = User::find($run->created_by)) {
            $preparer->notify(new PayrollRunDecision($run, 'approved'));
        }

        $run = $run->fresh();
        // Other modules settle the inputs they fed into the run (e.g. Attendance marks overtime paid).
        // The approval is committed; a failing listener is logged and never undoes it.
        \App\Modules\Employees\Support\SideEffects::dispatch(new PayrollRunApproved($run));

        return $run;
    }

    /** Applies loan instalments and advance recoveries once a run is approved (idempotent per item). */
    private function postDeductions(PayrollRun $run): void
    {
        $items = PayrollRunItem::whereIn('payroll_run_employee_id', PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id)->select('id'))
            ->whereIn('source', ['loan', 'loan_settlement', 'advance'])->get();

        foreach ($items as $item) {
            if ($item->source === 'loan') {
                $inst = PayrollLoanInstalment::find($item->source_id);
                if (! $inst || $inst->status !== 'pending') {
                    continue;
                }
                $inst->update(['status' => 'deducted', 'payroll_run_id' => $run->id, 'deducted_at' => now()]);
                $loan = $inst->loan;
                $pending = $loan->schedule()->where('status', 'pending')->count();
                // Balance = remaining scheduled repayments (principal + interest) after this deduction.
                $loan->update([
                    'amount_repaid' => round($loan->amount_repaid + $inst->amount, 2),
                    'balance' => $pending ? $loan->remainingRepayments() : 0,
                    'status' => $pending ? $loan->status : 'completed',
                    'settled_at' => $pending ? null : now(),
                ]);
            } elseif ($item->source === 'loan_settlement') {
                $loan = PayrollLoan::withoutGlobalScopes()->find($item->source_id);
                if (! $loan || in_array($loan->status, ['settled', 'completed'], true)) {
                    continue;
                }
                $loan->schedule()->where('status', 'pending')->update(['status' => 'settled', 'payroll_run_id' => $run->id, 'deducted_at' => now()]);
                $loan->update(['amount_repaid' => round($loan->amount_repaid + $item->amount, 2), 'balance' => 0, 'status' => 'settled', 'settled_at' => now()]);
            } else {
                $adv = PayrollSalaryAdvance::withoutGlobalScopes()->whereKey($item->source_id)->where('status', 'approved')->first();
                if (! $adv) {
                    continue;
                }
                $recovered = round(min((float) $adv->amount, (float) $adv->amount_recovered + (float) $item->amount), 2);
                $done = ($adv->amount - $recovered) < 0.01;
                $adv->update(['amount_recovered' => $recovered] + ($done ? ['status' => 'recovered', 'recovered_run_id' => $run->id] : []));
            }
        }
    }

    public function reject(PayrollRun $run, User $actor, string $reason): PayrollRun
    {
        if ($run->status !== 'reviewed') {
            throw ValidationException::withMessages(['status' => 'Only a run awaiting approval can be rejected.']);
        }
        abort_unless($actor->can('payroll.approve'), 403);
        $this->quietly($run, ['status' => 'calculated', 'reviewed_by' => null, 'reviewed_at' => null]);
        activity('payroll')->performedOn($run)->causedBy($actor)->withProperties(['reason' => $reason])->log('Payroll rejected');
        if ($preparer = User::find($run->created_by)) {
            $preparer->notify(new PayrollRunDecision($run, 'rejected', $reason));
        }

        return $run->fresh();
    }

    public function markPaid(PayrollRun $run, User $actor): PayrollRun
    {
        if ($run->status !== 'approved') {
            throw ValidationException::withMessages(['status' => 'Only an approved run can be marked as paid.']);
        }
        abort_if($this->ownPayBlocked($run, $actor), 403, self::OWN_PAY_MESSAGE);
        $this->logOwnPaySignoff($run, $actor, 'marked paid');
        $this->quietly($run, ['status' => 'paid', 'paid_by' => $actor->id, 'paid_at' => now()]);
        $this->calendarRow(Company::find($run->company_id), $run->period)?->update(['status' => 'paid']);
        activity('payroll')->performedOn($run)->causedBy($actor)->log('Payroll marked as paid');

        return $run->fresh();
    }

    /** Workflow steps write their own audit entry ("Payroll calculated", "Payroll approved" …); skip the generic "updated" row. */
    private function quietly(PayrollRun $run, array $attributes): void
    {
        activity()->withoutLogs(fn () => $run->update($attributes));
    }

    /**
     * Another module changed a payroll input (e.g. an expense queued for payroll): open runs that are already
     * calculated must be recalculated before review. Optionally limited to runs paying the given employees.
     */
    public static function inputsChanged(int $companyId, ?array $employeeIds = null): void
    {
        PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('status', ['calculated', 'reviewed'])
            ->where(fn ($q) => $q->where('type', 'regular')->orWhere('off_cycle_reason', 'final_dues'))
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', PayrollRunEmployee::withoutGlobalScopes()
                ->whereIn('employee_id', $employeeIds)->select('payroll_run_id')))
            ->get()->each(fn (PayrollRun $r) => app(self::class)->markDirty($r));
    }

    public function markDirty(PayrollRun $run): void
    {
        $run->update(['needs_recalculation' => true] + ($run->status === 'reviewed' ? ['status' => 'calculated', 'reviewed_by' => null, 'reviewed_at' => null] : []));
    }
}
