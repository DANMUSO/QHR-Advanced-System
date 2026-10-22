<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeDependant;
use App\Models\EmployeeQualification;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeTransfer;
use App\Modules\Employees\Models\KycCheck;
use Illuminate\Database\Seeder;

class EmployeeExtrasSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedQualifications($company);
            $this->seedDependants($company);
            $this->seedTransfers($company);
            $this->seedKyc($company);
        });
    }

    private function seedQualifications(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $qualifications = [
            ['degree',       'Bachelor of Commerce',                  'University of Nairobi',          2015],
            ['degree',       'Bachelor of Science (IT)',              'Strathmore University',          2016],
            ['diploma',      'Diploma in Business Management',        'Kenya Institute of Management',  2013],
            ['professional', 'CPA (K)',                               'KASNEB',                         2018],
            ['professional', 'CISM',                                  'ISACA',                          2019],
            ['certificate',  'Certificate in HR Management',          'IHRM',                           2017],
            ['masters',      'Master of Business Administration',     'USIU Africa',                    2020],
            ['secondary',    'Kenya Certificate of Secondary Education','KNEC',                         2010],
        ];

        foreach ($employees as $employee) {
            if (EmployeeQualification::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $count  = rand(1, 3);
            $picked = array_rand($qualifications, min($count, count($qualifications)));
            foreach ((array) $picked as $idx) {
                [$level, $title, $institution, $year] = $qualifications[$idx];
                EmployeeQualification::create([
                    'employee_id'    => $employee->id,
                    'level'          => $level,
                    'title'          => $title,
                    'institution'    => $institution,
                    'year_completed' => $year,
                    'grade'          => collect(['First Class', 'Second Class Upper', 'Second Class Lower', 'Pass'])->random(),
                ]);
            }
        }
    }

    private function seedDependants(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->where('marital_status', 'married')
            ->get();

        foreach ($employees as $employee) {
            if (EmployeeDependant::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $count = rand(1, 3);
            for ($i = 0; $i < $count; $i++) {
                $rel = $i === 0 ? 'spouse' : 'child';
                EmployeeDependant::create([
                    'employee_id'   => $employee->id,
                    'name'          => fake()->name(),
                    'relationship'  => $rel,
                    'date_of_birth' => $rel === 'spouse'
                        ? now()->subYears(rand(25, 50))->toDateString()
                        : now()->subYears(rand(1, 18))->toDateString(),
                ]);
            }
        }
    }

    private function seedTransfers(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $departments = \DB::table('departments')->where('company_id', $company->id)->pluck('id')->toArray();
        $branches    = \DB::table('branches')->where('company_id', $company->id)->pluck('id')->toArray();

        if (count($departments) < 2 || count($branches) < 1) return;

        $processedBy = User::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))
            ->value('id');

        $types = ['transfer', 'promotion', 'redeployment'];

        foreach ($employees->take(10) as $employee) {
            if (EmployeeTransfer::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $fromDept = $employee->department_id;
            $toDept   = collect($departments)->reject(fn ($d) => $d === $fromDept)->random();

            EmployeeTransfer::create([
                'company_id'         => $company->id,
                'employee_id'        => $employee->id,
                'type'               => $types[array_rand($types)],
                'from_department_id' => $fromDept,
                'to_department_id'   => $toDept,
                'from_branch_id'     => $employee->branch_id,
                'to_branch_id'       => collect($branches)->random(),
                'from_position_id'   => $employee->position_id,
                'effective_date'     => now()->subDays(rand(30, 365))->toDateString(),
                'reason'             => collect([
                    'Operational requirements',
                    'Career development',
                    'Restructuring',
                    'Performance-based promotion',
                ])->random(),
                'status'             => 'completed',
                'applied_at'         => now()->subDays(rand(35, 370)),
                'processed_by'       => $processedBy,
            ]);
        }
    }

    private function seedKyc(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $verifiedBy = User::withoutGlobalScopes()->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))
            ->value('id');

        $checkTypes = ['identity', 'background'];

        foreach ($employees as $employee) {
            if (KycCheck::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            foreach ($checkTypes as $type) {
                $status = collect(['pending', 'verified', 'verified', 'verified', 'failed'])->random();
                KycCheck::create([
                    'company_id'  => $company->id,
                    'employee_id' => $employee->id,
                    'check_type'  => $type,
                    'status'      => $status,
                    'provider'    => 'internal',
                    'reference'   => strtoupper($type[0]) . 'KYC-' . rand(10000, 99999),
                    'result'      => $status === 'verified'
                        ? ['outcome' => 'clear', 'checked_at' => now()->subDays(rand(1, 180))->toDateString()]
                        : null,
                    'notes'       => $status === 'failed' ? 'Discrepancy found — follow up required.' : null,
                    'verified_by' => $status === 'verified' ? $verifiedBy : null,
                    'verified_at' => $status === 'verified' ? now()->subDays(rand(1, 180)) : null,
                ]);
            }
        }
    }
}
