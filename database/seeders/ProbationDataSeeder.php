<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Probation\Models\ProbationReview;
use Illuminate\Database\Seeder;

class ProbationDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            // Employees currently on probation
            $onProbation = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('probation_status', 'on_probation')
                ->get();

            foreach ($onProbation as $employee) {
                if (ProbationReview::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                    continue;
                }

                $endDate = $employee->probation_end_date ?? now()->addMonths(2);
                $status  = $endDate->isPast() ? 'primary_done' : 'pending';

                ProbationReview::create([
                    'company_id'        => $company->id,
                    'employee_id'       => $employee->id,
                    'probation_end_date'=> $endDate->toDateString(),
                    'status'            => $status,
                    'primary_comment'   => $status === 'primary_done'
                        ? 'Employee has shown good progress. Recommend confirmation.'
                        : null,
                    'primary_reviewed_at' => $status === 'primary_done' ? now()->subDays(rand(1, 14)) : null,
                ]);
            }

            // A few recently confirmed employees
            $recentJoiners = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('probation_status', 'confirmed')
                ->where('date_of_joining', '>=', now()->subMonths(8))
                ->take(5)
                ->get();

            foreach ($recentJoiners as $employee) {
                if (ProbationReview::withoutGlobalScopes()->where('employee_id', $employee->id)->exists()) {
                    continue;
                }

                $endDate = $employee->probation_end_date ?? now()->subMonths(1);

                ProbationReview::create([
                    'company_id'          => $company->id,
                    'employee_id'         => $employee->id,
                    'probation_end_date'  => $endDate->toDateString(),
                    'status'              => 'confirmed',
                    'decision'            => 'confirm',
                    'primary_comment'     => 'Excellent performance during probation. Highly recommend confirmation.',
                    'primary_reviewed_at' => $endDate->copy()->subDays(7),
                    'hr_comment'          => 'Confirmed as per supervisor recommendation.',
                    'hr_reviewed_at'      => $endDate->copy()->subDays(3),
                ]);
            }

            // One extended probation
            $extendCandidate = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('probation_status', 'on_probation')
                ->first();

            if ($extendCandidate && ! ProbationReview::withoutGlobalScopes()->where('employee_id', $extendCandidate->id)->exists()) {
                ProbationReview::create([
                    'company_id'          => $company->id,
                    'employee_id'         => $extendCandidate->id,
                    'probation_end_date'  => now()->subDays(10)->toDateString(),
                    'status'              => 'extended',
                    'decision'            => 'extend',
                    'extension_end_date'  => now()->addMonths(3)->toDateString(),
                    'primary_comment'     => 'Employee needs more time to demonstrate required competencies.',
                    'primary_reviewed_at' => now()->subDays(12),
                    'hr_comment'          => 'Probation extended by 3 months. Monthly check-ins required.',
                    'hr_reviewed_at'      => now()->subDays(8),
                ]);
            }
        });
    }
}
