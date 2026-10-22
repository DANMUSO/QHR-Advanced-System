<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Performance\Models\CareerPath;
use App\Modules\Performance\Models\PotentialRating;
use App\Modules\Performance\Models\SuccessionPlan;
use App\Modules\Performance\Models\TalentPool;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent succession & talent demo data.
 * company_id is guarded on these models and there is no authenticated user in the console,
 * so every row stamps company_id explicitly (forceFill) — otherwise inserts fail with a NULL company_id.
 */
class SuccessionSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedTalentPools($company);
            $this->seedSuccessionPlans($company);
            $this->seedCareerPaths($company);
            $this->seedPotentialRatings($company);
        });
    }

    private function activeEmployees(Company $company)
    {
        return Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->orderBy('id')->get();
    }

    private function seedTalentPools(Company $company): void
    {
        $pools = [
            ['High Potentials', 'Employees identified as future leaders.', 'Consistent high performance and leadership potential.'],
            ['Technical Experts', 'Deep technical specialists critical to operations.', 'Demonstrated technical mastery and innovation.'],
            ['Succession Pipeline', 'Employees being groomed for senior roles.', 'Ready for promotion within 1-2 years.'],
            ['Graduate Trainees', 'Recent graduates on structured development.', 'University degree and strong aptitude test scores.'],
        ];

        $employees = $this->activeEmployees($company);
        if ($employees->isEmpty()) {
            return;
        }

        foreach ($pools as $i => [$name, $description, $criteria]) {
            $pool = TalentPool::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $name)->first();
            if (! $pool) {
                $pool = (new TalentPool)->forceFill(['company_id' => $company->id, 'name' => $name, 'description' => $description, 'criteria' => $criteria]);
                $pool->save();
            }

            if (DB::table('succ_talent_pool_members')->where('talent_pool_id', $pool->id)->exists()) {
                continue;
            }
            // deterministic slice so re-runs are stable
            foreach ($employees->slice($i * 5, 3 + $i)->values() as $employee) {
                DB::table('succ_talent_pool_members')->updateOrInsert(
                    ['talent_pool_id' => $pool->id, 'employee_id' => $employee->id],
                    ['company_id' => $company->id, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    private function seedSuccessionPlans(Company $company): void
    {
        $supervisors = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
            ->whereNotNull('position_id')
            ->whereHas('position', fn ($q) => $q->withoutGlobalScopes()->where('is_supervisor', true))->orderBy('id')->get();
        $staff = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
            ->whereHas('position', fn ($q) => $q->withoutGlobalScopes()->where('is_supervisor', false))->orderBy('id')->get();

        if ($staff->isEmpty()) {
            return;
        }

        $readiness = ['ready_now', 'ready_1_2_years', 'ready_3_5_years', 'ready_1_2_years'];
        $risk = ['high', 'medium', 'low', 'medium'];

        foreach ($supervisors->take(8)->values() as $i => $supervisor) {
            if (SuccessionPlan::withoutGlobalScopes()->where('incumbent_id', $supervisor->id)->exists()) {
                continue;
            }
            $successor = $staff->firstWhere('department_id', $supervisor->department_id) ?? $staff[$i % $staff->count()];

            (new SuccessionPlan)->forceFill([
                'company_id' => $company->id,
                'position_id' => $supervisor->position_id,
                'incumbent_id' => $supervisor->id,
                'successor_id' => $successor->id,
                'readiness' => $readiness[$i % 4],
                'risk_level' => $risk[$i % 4],
                'notes' => 'Identified during the annual talent review session.',
            ])->save();
        }
    }

    private function seedCareerPaths(Company $company): void
    {
        $positions = DB::table('positions')->where('company_id', $company->id)->get();
        if ($positions->count() < 2) {
            return;
        }

        $paths = [
            ['Customer Care Agent', 'Customer Experience Lead', 2, 'Consistent high performance and leadership skills.'],
            ['Operations Assistant', 'Operations Coordinator', 2, 'Demonstrated operational excellence.'],
            ['Sales Executive', 'Sales Manager', 3, 'Top sales performer for 2+ years.'],
            ['Systems Developer', 'ICT Manager', 4, 'Technical expertise and project delivery.'],
            ['HR Officer', 'HR Manager', 3, 'IHRM certification and 3 years experience.'],
            ['Credit Analyst', 'Credit Manager', 3, 'CPA qualification and risk management skills.'],
        ];

        $made = 0;
        foreach ($paths as [$fromTitle, $toTitle, $minYears, $requirements]) {
            $from = $positions->firstWhere('title', $fromTitle);
            $to = $positions->firstWhere('title', $toTitle);
            if (! $from || ! $to) {
                continue;
            }
            $this->careerPath($company, $from, $to, "{$fromTitle} → {$toTitle}", $minYears, $requirements);
            $made++;
        }

        // Companies with a different position catalogue: chain non-supervisory roles to supervisory ones.
        if ($made === 0) {
            $juniors = $positions->where('is_supervisor', false)->values();
            $seniors = $positions->where('is_supervisor', true)->values();
            foreach ($juniors->take(4) as $i => $from) {
                if ($to = $seniors[$i % max(1, $seniors->count())] ?? null) {
                    $this->careerPath($company, $from, $to, "{$from->title} → {$to->title}", 3, 'Strong appraisal results and leadership training.');
                }
            }
        }
    }

    private function careerPath(Company $company, object $from, object $to, string $name, int $minYears, string $requirements): void
    {
        $exists = CareerPath::withoutGlobalScopes()->where('company_id', $company->id)
            ->where('from_position_id', $from->id)->where('to_position_id', $to->id)->exists();
        if (! $exists) {
            (new CareerPath)->forceFill([
                'company_id' => $company->id, 'from_position_id' => $from->id, 'to_position_id' => $to->id,
                'name' => $name, 'min_years' => $minYears, 'requirements' => $requirements,
            ])->save();
        }
    }

    /** Potential ratings (1 low – 3 high) for everyone appraised in the latest cycle that has scores, so the 9-box fills. */
    private function seedPotentialRatings(Company $company): void
    {
        $cycleId = DB::table('perf_appraisals')->where('company_id', $company->id)->whereNotNull('final_score')
            ->orderByDesc('cycle_id')->value('cycle_id');
        if (! $cycleId) {
            return;
        }

        $raterUser = User::where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))->value('id');

        $appraisals = DB::table('perf_appraisals')->where('cycle_id', $cycleId)->whereNotNull('final_score')->orderBy('employee_id')->get();
        foreach ($appraisals as $i => $a) {
            if (PotentialRating::withoutGlobalScopes()->where('cycle_id', $cycleId)->where('employee_id', $a->employee_id)->exists()) {
                continue;
            }
            (new PotentialRating)->forceFill([
                'company_id' => $company->id,
                'cycle_id' => $cycleId,
                'employee_id' => $a->employee_id,
                'potential' => [2, 3, 1, 2, 3][$i % 5],
                'note' => 'Assessed during the annual talent calibration session.',
                'rated_by_user_id' => $raterUser,
            ])->save();
        }
    }
}
