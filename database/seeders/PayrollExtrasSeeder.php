<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Payroll\Models\PayrollLoan;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Payroll\Services\LoanService;
use Illuminate\Database\Seeder;

class PayrollExtrasSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedLoans($company);
            $this->seedAdvances($company);
            $this->seedContracts($company);
        });
    }

    /**
     * Staff loans created through LoanService so the amortisation schedule, interest (flat or reducing)
     * and balance (principal + interest) are consistent. Repayments start in the current month, so the
     * first payroll run the user calculates deducts the first instalment and approval reduces the balance.
     */
    private function seedLoans(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->whereIn('id', \DB::table('payroll_pay_profiles')->where('company_id', $company->id)->select('employee_id'))
            ->orderBy('id')
            ->take(8)
            ->get();

        $actor = User::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->first();
        if (! $actor) {
            return;
        }
        $service = app(LoanService::class);

        foreach ($employees as $i => $employee) {
            $existing = PayrollLoan::withoutGlobalScopes()->where('employee_id', $employee->id)->first();
            if ($existing) {
                // Repair loans from the old seeder: balance without interest or without a pending schedule.
                $pending = $existing->schedule()->where('status', 'pending')->count();
                if (! $pending && in_array($existing->status, ['active', 'paused'], true)) {
                    $existing->schedule()->delete();
                    $existing->delete();
                } else {
                    continue;
                }
            }

            $method = $i % 3 === 2 ? 'reducing' : 'flat';
            // Affordable demo loans: principal 1-2.5x basic, instalment at most ~20% of basic.
            $basic = (float) (\DB::table('payroll_pay_profiles')->where('employee_id', $employee->id)->value('basic_salary') ?: 40000);
            $principal = max(10000, round($basic * [1, 1.5, 2, 2.5][$i % 4] / 1000) * 1000);
            $instalments = (int) min(36, max([6, 12, 18, 24][$i % 4], ceil($principal / ($basic * 0.2))));
            $service->create($employee, [
                'principal' => $principal,
                'interest_method' => $method,
                'interest_rate' => $i % 4 === 0 ? 0 : [6, 8, 10, 12][$i % 4],
                'instalments' => $instalments,
                'start_period' => now()->format('Y-m'),
                'description' => ['Emergency Loan', 'Car Loan', 'School Fees Loan', 'SACCO Loan'][$i % 4],
                'notes' => 'Approved by HR and Finance.',
            ], $actor);
        }
    }

    private function seedAdvances(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->take(10)
            ->get();

        $statuses = ['pending', 'approved', 'approved', 'rejected', 'approved'];
        $reasons  = [
            'Medical emergency for family member',
            'School fees payment',
            'House rent deposit',
            'Car repair',
            'Funeral expenses',
        ];

        $decidedBy = User::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'finance']))
            ->value('id');

        foreach ($employees->take(6) as $employee) {
            if (PayrollSalaryAdvance::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $status = $statuses[array_rand($statuses)];
            $basic = (float) (\DB::table('payroll_pay_profiles')->where('employee_id', $employee->id)->value('basic_salary') ?: 40000);
            $amount = max(5000, round($basic * rand(15, 30) / 100 / 1000) * 1000); // within the advance limit

            PayrollSalaryAdvance::create([
                'company_id'      => $company->id,
                'employee_id'     => $employee->id,
                'amount'          => $amount,
                'reason'          => $reasons[array_rand($reasons)],
                'status'          => $status,
                'decided_by'      => in_array($status, ['approved', 'rejected']) ? $decidedBy : null,
                'decided_at'      => in_array($status, ['approved', 'rejected'])
                    ? now()->subDays(rand(1, 10)) : null,
                'decision_note'   => $status === 'rejected'
                    ? 'Advance limit exceeded for this period.' : null,
                'recovery_period' => $status === 'approved'
                    ? now()->addMonths(rand(1, 3))->startOfMonth()->toDateString() : null,
            ]);
        }
    }

    private function seedContracts(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $adminUser = User::withoutGlobalScopes()->where('company_id', $company->id)->value('id');
        $counter   = 1;

        foreach ($employees as $employee) {
            if (EmployeeContract::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $joined       = $employee->date_of_joining ?? now()->subYears(2);
            $contractType = match ($employee->contract_type) {
                'contract' => 'fixed_term',
                'intern'   => 'internship',
                'casual'   => 'casual',
                default    => 'permanent',
            };
            $endDate = in_array($contractType, ['fixed_term', 'internship', 'casual'])
                ? now()->addDays(rand(30, 365))->toDateString()
                : null;

            $basic = \DB::table('payroll_pay_profiles')
                ->where('employee_id', $employee->id)
                ->value('basic_salary') ?? rand(40000, 150000);

            EmployeeContract::create([
                'company_id'    => $company->id,
                'employee_id'   => $employee->id,
                'reference'     => $company->code . '-CTR-' . str_pad((string) $counter++, 4, '0', STR_PAD_LEFT),
                'contract_type' => $contractType,
                'job_title'     => $employee->position?->title,
                'start_date'    => $joined->toDateString(),
                'end_date'      => $endDate,
                'gross_salary'  => round($basic * 1.3),
                'status'        => 'active',
                'notes'         => 'Initial employment contract.',
                'created_by'    => $adminUser,
            ]);
        }
    }
}
