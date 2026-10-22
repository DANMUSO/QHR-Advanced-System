<?php

namespace App\Modules\Benefits\Database;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Benefits\Models\BenefitPlan;
use App\Modules\Benefits\Models\PensionMember;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Idempotent: completes the benefit catalogue, enrols employees and creates pension / SACCO memberships. */
class DemoSeeder extends Seeder
{
    private const CATALOGUE = [
        // name => [type, value, eligibility, description]
        'Medical Cover' => ['insurance', 'Inpatient KES 1,000,000 / Outpatient KES 100,000', 'all', 'Group medical cover for staff and declared dependants.'],
        'Group Life Insurance' => ['insurance', '3x annual basic salary', 'all', 'Group life and last expense cover.'],
        'Pension Scheme' => ['pension', 'Employee 5% / Employer 10% of basic', 'permanent', 'Defined contribution staff retirement benefits scheme.'],
        'Airtime Allowance' => ['allowance', 'KES 2,000 / month', 'management', 'Monthly airtime for managers and field staff.'],
        'Transport Allowance' => ['allowance', 'KES 5,000 / month', 'all', 'Monthly commuter allowance.'],
        'Study Leave' => ['leave', 'Up to 10 days / year', 'confirmed', 'Paid leave for approved professional examinations.'],
        'Staff Car Loan Scheme' => ['other', 'Up to KES 1.5M at 6% p.a.', 'confirmed', 'Subsidised car loans repaid through payroll.'],
    ];

    public function run(): void
    {
        if (! Schema::hasColumn('benefits', 'type') || ! Schema::hasTable('benefit_pension_members')) {
            return; // module migration not run yet
        }

        foreach (Company::all() as $company) {
            $this->seedCatalogue($company);
            $this->seedEnrolments($company);
            $this->seedPension($company);
        }
    }

    private function seedCatalogue(Company $company): void
    {
        foreach (self::CATALOGUE as $name => [$type, $value, $eligibility, $description]) {
            $benefit = BenefitPlan::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $name)->first()
                ?? (new BenefitPlan)->forceFill(['company_id' => $company->id, 'name' => $name]);
            // only fill blanks so admin edits survive re-runs
            $benefit->forceFill([
                'type' => $benefit->exists && $benefit->type !== 'other' ? $benefit->type : $type,
                'value' => $benefit->value ?: $value,
                'eligibility' => $benefit->exists && $benefit->eligibility !== 'all' ? $benefit->eligibility : $eligibility,
                'description' => $benefit->description ?: $description,
            ]);
            if (! $benefit->exists) {
                $benefit->is_active = true;
            }
            $benefit->save();
        }
    }

    private function seedEnrolments(Company $company): void
    {
        $benefits = BenefitPlan::withoutGlobalScopes()->where('company_id', $company->id)->pluck('id', 'name');
        if (DB::table('benefit_employee')->whereIn('benefit_id', $benefits->values())->exists()) {
            return;
        }
        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->orderBy('id')->get();

        $rows = [];
        foreach ($employees as $i => $e) {
            foreach (['Medical Cover', 'Group Life Insurance', 'Transport Allowance'] as $name) {
                if (isset($benefits[$name])) {
                    $rows[] = ['benefit_id' => $benefits[$name], 'employee_id' => $e->id];
                }
            }
            if (isset($benefits['Pension Scheme']) && $e->contract_type !== 'casual' && $i % 5 !== 4) {
                $rows[] = ['benefit_id' => $benefits['Pension Scheme'], 'employee_id' => $e->id];
            }
            if (isset($benefits['Airtime Allowance']) && $i % 4 === 0) {
                $rows[] = ['benefit_id' => $benefits['Airtime Allowance'], 'employee_id' => $e->id];
            }
        }
        DB::table('benefit_employee')->insertOrIgnore($rows);
    }

    private function seedPension(Company $company): void
    {
        if (PensionMember::withoutGlobalScopes()->where('company_id', $company->id)->exists()) {
            return;
        }
        $short = strtok($company->name, ' ');
        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
            ->orderBy('id')->take(30)->get();

        foreach ($employees as $i => $e) {
            $basic = 45000 + ($i % 7) * 12500;
            $joined = $e->date_of_joining ?? now()->subYears(2);
            $months = max(1, (int) $joined->diffInMonths(now()));
            $empC = round($basic * 0.05, 2);
            $erC = round($basic * 0.10, 2);

            (new PensionMember)->forceFill([
                'company_id' => $company->id,
                'employee_id' => $e->id,
                'scheme_type' => 'pension',
                'scheme_name' => "{$short} Staff Retirement Benefits Scheme",
                'member_number' => sprintf('PEN-%d-%04d', $company->id, $e->id),
                'employee_contribution' => $empC,
                'employer_contribution' => $erC,
                'total_balance' => round(($empC + $erC) * $months * 1.04, 2),
                'joined_date' => $joined->toDateString(),
                'status' => 'active',
            ])->save();

            if ($i % 3 === 0) {
                $saving = 3000 + ($i % 5) * 1000;
                (new PensionMember)->forceFill([
                    'company_id' => $company->id,
                    'employee_id' => $e->id,
                    'scheme_type' => 'sacco',
                    'scheme_name' => "{$short} Staff SACCO",
                    'member_number' => sprintf('SAC-%d-%04d', $company->id, $e->id),
                    'employee_contribution' => $saving,
                    'employer_contribution' => 0,
                    'total_balance' => $saving * min($months, 36),
                    'joined_date' => $joined->copy()->addMonths(3)->toDateString(),
                    'status' => 'active',
                ])->save();
            }
        }
    }
}
