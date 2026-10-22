<?php

namespace App\Modules\Payroll\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Notifications\SalaryAdvanceNotification;
use App\Modules\Payroll\Services\Calculation\LoanSchedule;
use App\Modules\Payroll\Support\PayrollSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public const MAX_ADVANCE_MONTHS = 6;

    // ---------------------------------------------------------------- salary advances (single source for every entry point)

    /** Largest advance the employee may request: advance_max_percent of current basic salary. */
    public function advanceLimit(Employee $employee): array
    {
        $profile = PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->id)->first();
        $company = Company::find($employee->company_id);
        $percent = (float) ($company ? PayrollSettings::get($company, 'advance_max_percent') : PayrollSettings::DEFAULTS['advance_max_percent']);

        return ['profile' => $profile, 'percent' => $percent, 'max' => $profile ? round($profile->basic_salary * $percent / 100, 2) : 0.0];
    }

    /** First month whose regular payroll is still open: recovery starts there. */
    public function nextRecoveryPeriod(int $companyId): CarbonImmutable
    {
        $period = CarbonImmutable::now()->startOfMonth();
        while (PayrollRun::withoutGlobalScopes()->where('company_id', $companyId)->where('type', 'regular')
            ->whereDate('period', $period->toDateString())->whereIn('status', ['approved', 'paid'])->exists()) {
            $period = $period->addMonth();
        }

        return $period;
    }

    /**
     * Employee requests a salary advance (portal, expenses page or payroll self-service all land here).
     * Enforces advance_max_percent and one open advance at a time, sets the recovery period and notifies payroll.
     */
    public function requestAdvance(Employee $employee, float $amount, string $reason, int $repaymentMonths, ?User $actor = null): PayrollSalaryAdvance
    {
        ['profile' => $profile, 'percent' => $percent, 'max' => $max] = $this->advanceLimit($employee);
        if (! $profile || $profile->basic_salary <= 0) {
            throw ValidationException::withMessages(['amount' => 'You do not have a pay profile yet. Contact the payroll office.']);
        }
        if ($amount > $max) {
            throw ValidationException::withMessages(['amount' => 'The maximum advance is KES '.number_format($max, 2)." ({$percent}% of your basic salary)."]);
        }
        if ($repaymentMonths < 1 || $repaymentMonths > self::MAX_ADVANCE_MONTHS) {
            throw ValidationException::withMessages(['repayment_months' => 'Choose between 1 and '.self::MAX_ADVANCE_MONTHS.' months to repay.']);
        }
        if (PayrollSalaryAdvance::withoutGlobalScopes()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->exists()) {
            throw ValidationException::withMessages(['amount' => 'You already have an advance that is pending or not yet recovered.']);
        }

        $advance = PayrollSalaryAdvance::create([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'amount' => round($amount, 2), 'reason' => $reason,
            'repayment_months' => $repaymentMonths, 'amount_recovered' => 0, 'status' => 'pending',
            'recovery_period' => $this->nextRecoveryPeriod($employee->company_id)->toDateString(),
        ]);
        activity('payroll')->performedOn($advance)->causedBy($actor)->withProperties(['amount' => $advance->amount, 'months' => $repaymentMonths])->log('Salary advance requested');

        $advance->load('employee');
        $this->advanceDeciders($employee->company_id, $employee->user_id)
            ->each(fn (User $u) => $u->notify(new SalaryAdvanceNotification($advance, 'requested')));

        return $advance;
    }

    /** Users who may approve an advance (payroll.manage or payroll.approve), never the requester. */
    public function advanceDeciders(int $companyId, ?int $exceptUserId = null)
    {
        return User::where('company_id', $companyId)->where('is_active', true)->permission(['payroll.manage', 'payroll.approve'])
            ->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))->get();
    }

    public function approveAdvance(PayrollSalaryAdvance $advance, User $actor, ?string $recoveryPeriod = null, ?int $repaymentMonths = null, ?string $note = null): PayrollSalaryAdvance
    {
        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
        }
        abort_if($advance->employee?->user_id === $actor->id, 403, 'You cannot approve your own salary advance.');
        if ($repaymentMonths !== null && ($repaymentMonths < 1 || $repaymentMonths > self::MAX_ADVANCE_MONTHS)) {
            throw ValidationException::withMessages(['repayment_months' => 'Choose between 1 and '.self::MAX_ADVANCE_MONTHS.' months.']);
        }
        $advance->update([
            'status' => 'approved', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
            'repayment_months' => $repaymentMonths ?? $advance->repayment_months ?? 1,
            'recovery_period' => $recoveryPeriod ? $recoveryPeriod.'-01'
                : ($advance->recovery_period?->toDateString() ?? $this->nextRecoveryPeriod($advance->company_id)->toDateString()),
        ]);
        activity('payroll')->performedOn($advance)->causedBy($actor)->log('Salary advance approved');
        $advance->employee?->user?->notify(new SalaryAdvanceNotification($advance->fresh('employee'), 'approved'));

        return $advance->fresh(['employee', 'decider']);
    }

    public function rejectAdvance(PayrollSalaryAdvance $advance, User $actor, string $reason): PayrollSalaryAdvance
    {
        if ($advance->status !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
        }
        abort_if($advance->employee?->user_id === $actor->id, 403, 'You cannot decide your own salary advance.');
        $advance->update(['status' => 'rejected', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $reason]);
        activity('payroll')->performedOn($advance)->causedBy($actor)->withProperties(['reason' => $reason])->log('Salary advance rejected');
        $advance->employee?->user?->notify(new SalaryAdvanceNotification($advance->fresh('employee'), 'rejected'));

        return $advance->fresh(['employee', 'decider']);
    }

    public function presentAdvance(PayrollSalaryAdvance $a): array
    {
        $months = max(1, (int) ($a->repayment_months ?: 1));

        return [
            'id' => $a->id, 'employee' => $a->employee ? ['id' => $a->employee->id, 'name' => $a->employee->full_name, 'employee_number' => $a->employee->employee_number] : null,
            'employee_name' => $a->employee?->full_name, 'amount' => $a->amount, 'reason' => $a->reason, 'status' => $a->status,
            'repayment_months' => $months, 'monthly_recovery' => round($a->amount / $months, 2),
            'amount_recovered' => (float) $a->amount_recovered, 'balance' => $a->remaining(),
            'decided_by' => $a->decider?->name, 'decided_at' => $a->decided_at, 'decision_note' => $a->decision_note,
            'recovery_period' => $a->recovery_period?->format('Y-m'), 'recovered_run_id' => $a->recovered_run_id, 'created_at' => $a->created_at,
        ];
    }

    // ---------------------------------------------------------------- loans

    public function create(Employee $employee, array $data, User $actor): PayrollLoan
    {
        $schedule = LoanSchedule::build((float) $data['principal'], $data['interest_method'] ?? 'flat',
            (float) ($data['interest_rate'] ?? 0), (int) $data['instalments'], $data['start_period'].(strlen($data['start_period']) === 7 ? '-01' : ''));

        return DB::transaction(function () use ($employee, $data, $actor, $schedule) {
            $component = PayrollComponent::withoutGlobalScopes()->where('company_id', $employee->company_id)->where('role', 'loan')->first();
            $loan = PayrollLoan::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'component_id' => $component?->id,
                'reference' => $data['reference'] ?? $this->nextReference($employee->company_id),
                'description' => $data['description'] ?? null,
                'principal' => round((float) $data['principal'], 2),
                'interest_method' => $data['interest_method'] ?? 'flat',
                'interest_rate' => (float) ($data['interest_rate'] ?? 0),
                'instalments' => (int) $data['instalments'],
                'start_period' => $schedule['rows'][0]['period'],
                'total_interest' => $schedule['total_interest'],
                'total_repayable' => $schedule['total_repayable'],
                'amount_repaid' => 0,
                'balance' => $schedule['total_repayable'], // principal + interest still to be repaid
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);
            $loan->schedule()->createMany(array_map(fn ($r) => array_diff_key($r, ['principal_balance' => true]) + ['status' => 'pending'], $schedule['rows']));
            activity('payroll')->performedOn($loan)->causedBy($actor)->withProperties([
                'employee_id' => $employee->id, 'principal' => $loan->principal, 'interest_method' => $loan->interest_method,
                'interest_rate' => $loan->interest_rate, 'total_interest' => $loan->total_interest, 'total_repayable' => $loan->total_repayable,
            ])->log('Loan created');

            return $loan;
        });
    }

    public function nextReference(int $companyId): string
    {
        $n = PayrollLoan::withoutGlobalScopes()->where('company_id', $companyId)->count() + 1;
        do {
            $ref = 'LN-'.now()->format('y').'-'.str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (PayrollLoan::withoutGlobalScopes()->where('company_id', $companyId)->where('reference', $ref)->exists());

        return $ref;
    }

    public function setStatus(PayrollLoan $loan, string $status, User $actor, ?string $note = null): PayrollLoan
    {
        $allowed = ['paused' => ['active'], 'active' => ['paused']];
        if (! in_array($loan->status, $allowed[$status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A {$loan->status} loan cannot be ".($status === 'paused' ? 'paused' : 'resumed').'.']);
        }
        $loan->update(['status' => $status]);
        activity('payroll')->performedOn($loan)->causedBy($actor)->withProperties(['note' => $note])->log($status === 'paused' ? 'Loan paused' : 'Loan resumed');

        return $loan;
    }

    /** Early settlement outside payroll (cash / bank): remaining instalments are closed. */
    public function settle(PayrollLoan $loan, User $actor, ?float $amount = null, ?string $note = null): PayrollLoan
    {
        if (in_array($loan->status, ['settled', 'completed'], true)) {
            throw ValidationException::withMessages(['status' => 'This loan is already closed.']);
        }
        $outstanding = $loan->remainingRepayments() ?: (float) $loan->balance;
        $paid = $amount ?? $outstanding;
        DB::transaction(function () use ($loan, $paid) {
            $loan->schedule()->where('status', 'pending')->update(['status' => 'settled', 'deducted_at' => now()]);
            $loan->update(['amount_repaid' => round($loan->amount_repaid + $paid, 2), 'balance' => 0, 'status' => 'settled', 'settled_at' => now()]);
        });
        activity('payroll')->performedOn($loan)->causedBy($actor)
            ->withProperties(['amount' => $paid, 'outstanding_before' => $outstanding, 'note' => $note])->log('Loan settled early');

        return $loan->fresh();
    }

    public function present(PayrollLoan $loan, bool $withSchedule = false): array
    {
        $rows = $loan->relationLoaded('schedule') ? $loan->schedule : $loan->schedule()->get();
        $pending = $rows->where('status', 'pending');
        $next = $pending->first();
        $out = [
            'id' => $loan->id,
            'reference' => $loan->reference,
            'description' => $loan->description,
            'employee' => $loan->employee ? ['id' => $loan->employee->id, 'name' => $loan->employee->full_name, 'employee_number' => $loan->employee->employee_number] : null,
            'employee_name' => $loan->employee?->full_name,
            'principal' => $loan->principal,
            'interest_method' => $loan->interest_method,
            'interest_rate' => $loan->interest_rate,
            'instalments' => $loan->instalments,
            'start_period' => $loan->start_period?->format('Y-m'),
            'total_interest' => $loan->total_interest,
            'total_repayable' => $loan->total_repayable,
            'amount_repaid' => $loan->amount_repaid,
            'balance' => $loan->balance,
            'outstanding_principal' => round((float) $pending->sum('principal'), 2),
            'outstanding_interest' => round((float) $pending->sum('interest'), 2),
            'monthly_deduction' => $next?->amount ?? 0,
            'next_period' => $next?->period?->format('Y-m'),
            'paid_instalments' => $rows->where('status', '!=', 'pending')->count(),
            'status' => $loan->status,
            'notes' => $loan->notes,
            'created_at' => $loan->created_at,
            'settled_at' => $loan->settled_at,
        ];
        if ($withSchedule) {
            $out['schedule'] = $rows->map(fn ($i) => [
                'number' => $i->number, 'period' => $i->period->format('Y-m'), 'principal' => $i->principal, 'interest' => $i->interest,
                'amount' => $i->amount, 'balance_after' => $i->balance_after, 'status' => $i->status, 'payroll_run_id' => $i->payroll_run_id,
            ])->values();
        }

        return $out;
    }
}
