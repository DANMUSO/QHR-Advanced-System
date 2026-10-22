<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Performance\Models\Certification;
use App\Modules\Performance\Models\Course;
use App\Modules\Performance\Models\Enrollment;
use App\Modules\Performance\Models\EmployeeSkill;
use App\Modules\Performance\Models\Skill;
use Illuminate\Database\Seeder;

class LearningSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedSkills($company);
            $this->seedCourses($company);
            $this->seedCertifications($company);
        });
    }

    private function seedSkills(Company $company): void
    {
        $skills = [
            ['Microsoft Excel',     'Technical'],
            ['Data Analysis',       'Technical'],
            ['Project Management',  'Management'],
            ['Communication',       'Soft Skills'],
            ['Leadership',          'Management'],
            ['Customer Service',    'Soft Skills'],
            ['Financial Reporting', 'Technical'],
            ['SQL / Databases',     'Technical'],
            ['Presentation Skills', 'Soft Skills'],
            ['Negotiation',         'Soft Skills'],
            ['Risk Management',     'Technical'],
            ['Team Management',     'Management'],
        ];

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $skillModels = [];
        foreach ($skills as [$name, $category]) {
            $skillModels[] = Skill::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['category' => $category]
            );
        }

        // level is tinyint 1-5
        foreach ($employees as $employee) {
            if (EmployeeSkill::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $assigned = array_rand($skillModels, min(count($skillModels), rand(3, 6)));
            foreach ((array) $assigned as $idx) {
                EmployeeSkill::create([
                    'skill_id'    => $skillModels[$idx]->id,
                    'employee_id' => $employee->id,
                    'company_id'  => $company->id,
                    'level'       => rand(1, 5),
                ]);
            }
        }
    }

    private function seedCourses(Company $company): void
    {
        $courses = [
            ['Leadership Essentials',            'Leadership',   'classroom', 16,  'Kenya School of Government'],
            ['Advanced Excel for Finance',        'Technical',    'online',    8,   'Coursera'],
            ['Project Management Professional',  'Management',   'blended',   40,  'PMI Kenya'],
            ['Customer Experience Excellence',   'Soft Skills',  'classroom', 8,   'Internal'],
            ['Data Analysis with Power BI',      'Technical',    'online',    12,  'Microsoft Learn'],
            ['HR for Non-HR Managers',           'Management',   'classroom', 8,   'IHRM'],
            ['Financial Modelling',              'Technical',    'online',    20,  'CFI'],
            ['Effective Communication',          'Soft Skills',  'classroom', 6,   'Internal'],
            ['Cybersecurity Awareness',          'Technical',    'online',    4,   'Internal'],
            ['Performance Management',           'Management',   'blended',   12,  'IHRM'],
        ];

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $statuses = ['enrolled', 'enrolled', 'in_progress', 'completed', 'completed', 'completed'];

        foreach ($courses as [$name, $category, $delivery, $hours, $provider]) {
            $course = Course::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'category'       => $category,
                    'delivery'       => $delivery,
                    'duration_hours' => $hours,
                    'provider'       => $provider,
                    'description'    => "Comprehensive {$name} training programme.",
                    'is_active'      => true,
                ]
            );

            $enrolled = $employees->random(min($employees->count(), rand(3, 8)));
            foreach ($enrolled as $employee) {
                if (Enrollment::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->where('course_id', $course->id)
                    ->exists()) {
                    continue;
                }

                $status    = $statuses[array_rand($statuses)];
                $startDate = now()->subDays(rand(10, 120));

                Enrollment::create([
                    'course_id'    => $course->id,
                    'employee_id'  => $employee->id,
                    'status'       => $status,
                    'start_date'   => $startDate->toDateString(),
                    'end_date'     => $status === 'completed'
                        ? $startDate->copy()->addDays(rand(5, 30))->toDateString() : null,
                    'progress'     => match ($status) {
                        'completed'   => 100,
                        'in_progress' => rand(20, 80),
                        default       => 0,
                    },
                    'completed_at' => $status === 'completed'
                        ? $startDate->copy()->addDays(rand(5, 30))->toDateString() : null,
                ]);
            }
        }
    }

    private function seedCertifications(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->get();

        $certs = [
            ['CPA (K)',                       'KASNEB',           36],
            ['CHRP',                          'IHRM',             24],
            ['PMP',                           'PMI',              36],
            ['CISM',                          'ISACA',            36],
            ['Microsoft Azure Fundamentals',  'Microsoft',        24],
            ['Google Analytics',              'Google',           12],
            ['First Aid Certificate',         'Kenya Red Cross',  12],
            ['OSHA Safety Certificate',       'DOSH Kenya',       24],
        ];

        foreach ($employees->take(15) as $employee) {
            if (Certification::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                continue;
            }

            $count  = rand(1, 2);
            $picked = array_rand($certs, min($count, count($certs)));
            foreach ((array) $picked as $idx) {
                [$name, $issuer, $validMonths] = $certs[$idx];
                $issueDate = now()->subMonths(rand(1, 24));

                Certification::create([
                    'company_id'    => $company->id,
                    'employee_id'   => $employee->id,
                    'name'          => $name,
                    'issuing_body'  => $issuer,
                    'issue_date'    => $issueDate->toDateString(),
                    'expiry_date'   => $issueDate->copy()->addMonths($validMonths)->toDateString(),
                    'credential_id' => strtoupper(substr($name, 0, 3)) . '-' . rand(10000, 99999),
                ]);
            }
        }
    }
}
