<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollEmployeeComponent;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Support\PayrollDefaults;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo payroll data. Historical runs are produced by the real calculation engine (PayrollRunService)
 * so every line has consistent statutory splits (NSSF tiers, employer contributions, tax charged,
 * reliefs, employer cost) and payslip items. Idempotent: re-running repairs malformed seeded runs.
 */
class PayrollDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedComponents($company);
            $this->seedPayProfiles($company);
            $this->seedRuns($company);
        });
    }

    private function seedComponents(Company $company): void
    {
        PayrollDefaults::ensureComponents($company->id); // standard components with roles (LOAN, ADVANCE, PEN_EE …)

        $extra = [
            ['code' => 'MEDICAL', 'name' => 'Medical allowance', 'category' => 'earning', 'calculation' => 'fixed', 'default_amount' => 3000, 'taxable' => true, 'recurring' => true, 'sort_order' => 14],
        ];
        foreach ($extra as $c) {
            PayrollComponent::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'code' => $c['code']],
                $c + ['company_id' => $company->id, 'active' => true, 'pensionable' => false],
            );
        }

        // Older demo data created duplicates of built-in items (basic is computed by the engine; pension uses PEN_EE / PEN_ER;
        // bonuses use the standard BONUS component, so no PERF_BONUS is created).
        PayrollComponent::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereIn('code', ['BASIC', 'PENSION_E', 'PENSION_ER'])->whereNull('role')->update(['active' => false]);
    }

    private function seedPayProfiles(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->get();

        $salaryByLevel = [
            'Management' => [180000, 350000],
            'Senior' => [100000, 180000],
            'Mid-level' => [60000, 100000],
            'Junior' => [30000, 60000],
        ];
        $house = PayrollComponent::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'HOUSE')->first();
        $transport = PayrollComponent::withoutGlobalScopes()->where('company_id', $company->id)->where('code', 'TRANSPORT')->first();

        foreach ($employees as $employee) {
            $joined = ($employee->date_of_joining ?? now()->subYear())->toDateString();

            if (! PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                [$min, $max] = $salaryByLevel[$employee->employmentLevel?->name ?? 'Junior'] ?? [30000, 60000];
                $basic = rand($min, $max);
                $bank = rand(0, 1) ? ['KCB Bank', '01', 'Moi Avenue', '01100'] : ['Equity Bank', '68', 'Upper Hill', '68050'];

                PayrollPayProfile::create([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'basic_salary' => $basic,
                    'payment_method' => rand(0, 3) ? 'bank' : 'mpesa',
                    'bank_name' => $bank[0],
                    'bank_code' => $bank[1],
                    'bank_branch' => $bank[2],
                    'branch_code' => $bank[3],
                    'account_number' => (string) rand(1000000000, 9999999999),
                    'account_name' => $employee->full_name,
                    'mpesa_phone' => '07'.rand(10000000, 99999999),
                ]);

                DB::table('payroll_salary_history')->insert([
                    'company_id' => $company->id,
                    'employee_id' => $employee->id,
                    'basic_salary' => $basic,
                    'previous_salary' => null,
                    'effective_date' => $joined,
                    'reason' => 'initial',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Standard allowances: house 15% of basic, transport KES 5,000.
            if (! PayrollEmployeeComponent::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                foreach (array_filter([$house, $transport]) as $component) {
                    PayrollEmployeeComponent::create([
                        'company_id' => $company->id,
                        'employee_id' => $employee->id,
                        'component_id' => $component->id,
                        'amount' => $component->calculation === 'fixed' ? $component->default_amount : null,
                        'rate' => $component->calculation === 'fixed' ? null : $component->default_rate,
                        'effective_from' => $joined,
                    ]);
                }
            }
        }
    }

    /** Six months of paid regular runs before the current month (the current month is left for the user to create). */
    private function seedRuns(Company $company): void
    {
        $users = User::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get();
        $preparer = $users->first(fn ($u) => $u->hasRole('payroll-officer')) ?? $users->first();
        if (! $preparer) {
            return;
        }
        $approver = $users->first(fn ($u) => $u->id !== $preparer->id && $u->hasRole(['finance', 'hr-admin'])) ?? $users->firstWhere('id', '!=', $preparer->id) ?? $preparer;

        $service = app(PayrollRunService::class);

        // Remove malformed demo runs from older seeders: draft runs carrying lines, or lines without NSSF tier / employer splits.
        PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'regular')->get()
            ->filter(fn (PayrollRun $r) => $this->isMalformed($r))
            ->each(function (PayrollRun $r) {
                DB::table('payroll_payment_files')->where('payroll_run_id', $r->id)->delete();
                $r->delete(); // lines and items cascade
            });

        for ($i = 6; $i >= 1; $i--) {
            $period = now()->startOfMonth()->subMonths($i);
            if (PayrollRun::withoutGlobalScopes()->where('company_id', $company->id)->where('type', 'regular')
                ->whereDate('period', $period->toDateString())->exists()) {
                continue;
            }

            $run = $service->create($company, ['period' => $period->format('Y-m'), 'type' => 'regular'], $preparer);
            $run = $service->calculate($run, $preparer);

            $end = $period->copy()->endOfMonth();
            // Historical workflow stamps (written directly so no notifications are sent while seeding).
            PayrollRun::withoutGlobalScopes()->whereKey($run->id)->update([
                'status' => 'paid',
                'calculated_at' => $end->copy()->subDays(6),
                'reviewed_by' => $preparer->id, 'reviewed_at' => $end->copy()->subDays(5),
                'approved_by' => $approver->id, 'approved_at' => $end->copy()->subDays(4),
                'paid_by' => $approver->id, 'paid_at' => $run->pay_date ?? $end->copy()->subDays(2),
            ]);
        }
    }

    private function isMalformed(PayrollRun $run): bool
    {
        $lines = PayrollRunEmployee::withoutGlobalScopes()->where('payroll_run_id', $run->id);
        if ($run->status === 'draft') {
            return (clone $lines)->exists();
        }

        return (clone $lines)->where('nssf_employee', '>', 0)
            ->where(fn ($q) => $q->where('nssf_employer', 0)->orWhereRaw('nssf_tier1 + nssf_tier2 = 0'))->exists();
    }
}
