<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\CoachingSession;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\FocusArea;
use App\Modules\Performance\Models\Frequency;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\GoalEvent;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\Objective;
use App\Modules\Performance\Models\Pip;
use App\Modules\Performance\Models\PipCheckin;
use App\Modules\Performance\Models\Plan;
use App\Modules\Performance\Models\Question;
use App\Modules\Performance\Models\QuestionCategory;
use App\Modules\Performance\Models\Questionnaire;
use App\Modules\Performance\Models\QuestionnaireComment;
use App\Modules\Performance\Models\QuestionnaireResponse;
use App\Modules\Performance\Models\RatingBand;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Performance\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PerformanceDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedSetup($company);
            $this->seedCyclesAndGoals($company);
        });
    }

    private function seedSetup(Company $company): void
    {
        foreach ([['Number', false], ['Percentage', true], ['KES', false], ['Hours', false]] as [$name, $isPct]) {
            Unit::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['is_percentage' => $isPct]
            );
        }

        foreach ([['Monthly', 30], ['Quarterly', 90], ['Annual', 365]] as [$name, $days]) {
            Frequency::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['days' => $days]
            );
        }

        foreach ([
            [1, 'Unsatisfactory',      'Performance is significantly below expectations'],
            [2, 'Needs Improvement',   'Performance partially meets expectations'],
            [3, 'Meets Expectations',  'Performance consistently meets expectations'],
            [4, 'Exceeds Expectations','Performance frequently exceeds expectations'],
            [5, 'Outstanding',         'Performance consistently exceeds all expectations'],
        ] as [$val, $label, $desc]) {
            RatingScale::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'value' => $val],
                ['label' => $label, 'description' => $desc]
            );
        }

        foreach ([
            ['Outstanding',          90, 'green'],
            ['Exceeds Expectations', 75, 'blue'],
            ['Meets Expectations',   60, 'yellow'],
            ['Needs Improvement',    40, 'orange'],
            ['Unsatisfactory',        0, 'red'],
        ] as [$name, $min, $color]) {
            RatingBand::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                ['min_score' => $min, 'color' => $color]
            );
        }

        $this->seedQuestions($company);
    }

    private function seedQuestions(Company $company): void
    {
        $categoryDefs = [
            'Leadership & Management' => [
                'Demonstrates clear vision and direction for the team.',
                'Effectively delegates tasks and empowers team members.',
                'Provides timely and constructive feedback.',
                'Manages conflict professionally and fairly.',
            ],
            'Communication' => [
                'Communicates clearly and concisely in written and verbal form.',
                'Actively listens and seeks to understand others.',
                'Shares information proactively with relevant stakeholders.',
            ],
            'Teamwork & Collaboration' => [
                'Works cooperatively with colleagues across departments.',
                'Contributes positively to team morale and culture.',
                'Supports colleagues during peak workloads.',
            ],
            'Customer Focus' => [
                'Consistently meets or exceeds customer expectations.',
                'Responds to customer needs promptly and professionally.',
                'Proactively identifies ways to improve customer experience.',
            ],
            'Innovation & Problem Solving' => [
                'Identifies problems early and proposes practical solutions.',
                'Demonstrates creativity and initiative in their work.',
                'Embraces change and adapts quickly to new processes.',
            ],
        ];

        foreach ($categoryDefs as $catName => $questions) {
            $category = QuestionCategory::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $catName],
                ['sort' => array_search($catName, array_keys($categoryDefs))]
            );

            foreach ($questions as $i => $text) {
                Question::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'category_id' => $category->id, 'question' => $text],
                    ['is_active' => true]
                );
            }
        }
    }

    private function seedCyclesAndGoals(Company $company): void
    {
        $yearNow  = now()->year;
        $yearPrev = $yearNow - 1;

        $units = Unit::withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('name');
        $freqs = Frequency::withoutGlobalScopes()->where('company_id', $company->id)->get()->keyBy('name');

        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->with('primarySupervisor')
            ->get();

        $supervisors = $employees->filter(fn ($e) => $e->position?->is_supervisor);

        // HR/admin user for recognitions and questionnaire assignments
        $hrUser = User::where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))
            ->first();

        foreach ([
            [$yearPrev, 'closed', "{$yearPrev}-01-01", "{$yearPrev}-12-31"],
            [$yearNow,  'active', "{$yearNow}-01-01",  "{$yearNow}-12-31"],
        ] as [$year, $status, $start, $end]) {
            $cycle = Cycle::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => "Annual Review {$year}"],
                [
                    'start_date'        => $start,
                    'end_date'          => $end,
                    'status'            => $status,
                    'kpi_weight'        => 70,
                    'competency_weight' => 30,
                    'max_score_pct'     => 120,
                ]
            );

            foreach ($supervisors->take(5) as $supervisor) {
                $plan = Plan::withoutGlobalScopes()->firstOrCreate(
                    ['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $supervisor->id],
                    ['name' => "{$supervisor->full_name} – {$year} Plan", 'start_date' => $start]
                );

                $focusArea = FocusArea::withoutGlobalScopes()->firstOrCreate(
                    ['plan_id' => $plan->id, 'name' => 'Operational Excellence'],
                    ['sort' => 1]
                );

                $objective = Objective::withoutGlobalScopes()->firstOrCreate(
                    ['plan_id' => $plan->id, 'focus_area_id' => $focusArea->id, 'name' => 'Team Productivity'],
                    ['weight' => 40, 'sort' => 1]
                );

                $kpiDefs = [
                    ['Revenue Target Achievement', 'quantitative', 'percentage', 100,  $units['Percentage'] ?? null, $freqs['Quarterly'] ?? null],
                    ['Customer Satisfaction Score', 'quantitative', 'absolute',  4.5,  $units['Number']     ?? null, $freqs['Monthly']   ?? null],
                    ['Team Attendance Rate',        'quantitative', 'percentage', 95,  $units['Percentage'] ?? null, $freqs['Monthly']   ?? null],
                ];

                foreach ($kpiDefs as [$kpiName, $type, $kpiType, $target, $unit, $freq]) {
                    $kpi = Kpi::withoutGlobalScopes()->firstOrCreate(
                        ['plan_id' => $plan->id, 'objective_id' => $objective->id, 'name' => $kpiName],
                        [
                            'company_id'   => $company->id,
                            'type'         => $type,
                            'kpi_type'     => $kpiType,
                            'target'       => $target,
                            'unit_id'      => $unit?->id,
                            'frequency_id' => $freq?->id,
                            'due_date'     => $end,
                        ]
                    );

                    $reports = $employees->where('primary_supervisor_id', $supervisor->id)->take(4);
                    foreach ($reports as $report) {
                        $goalStatus = $status === 'closed'
                            ? 'approved'
                            : collect(['in_progress', 'submitted', 'cascaded'])->random();
                        $achieved = $goalStatus === 'approved'
                            ? round($target * (rand(70, 120) / 100), 2)
                            : null;

                        $goal = Goal::withoutGlobalScopes()->firstOrCreate(
                            ['company_id' => $company->id, 'cycle_id' => $cycle->id, 'kpi_id' => $kpi->id, 'employee_id' => $report->id],
                            [
                                'assigned_by'     => $supervisor->id,
                                'approver_id'     => $supervisor->id,
                                'target'          => $target,
                                'due_date'        => $end,
                                'status'          => $goalStatus,
                                'achieved'        => $achieved,
                                'achievement_pct' => $achieved ? min(120, round(($achieved / $target) * 100, 2)) : 0,
                                'score'           => $achieved ? min(100, round(($achieved / $target) * 100, 2)) : 0,
                                'accepted_at'     => $goalStatus !== 'cascaded' ? now()->subMonths(rand(1, 6)) : null,
                                'submitted_at'    => in_array($goalStatus, ['submitted', 'approved']) ? now()->subMonths(rand(0, 2)) : null,
                                'approved_at'     => $goalStatus === 'approved' ? now()->subMonths(rand(0, 1)) : null,
                                'approved_by'     => $goalStatus === 'approved' ? $supervisor->id : null,
                            ]
                        );

                        $this->seedGoalEvents($goal, $report, $supervisor);
                    }
                }

                // Appraisals + questionnaires for closed cycle
                if ($status === 'closed') {
                    $reports = $employees->where('primary_supervisor_id', $supervisor->id)->take(4);
                    foreach ($reports as $report) {
                        $kpiScore  = rand(60, 100);
                        $compScore = rand(55, 95);
                        $final     = round($kpiScore * 0.7 + $compScore * 0.3, 2);
                        $band      = match (true) {
                            $final >= 90 => 'Outstanding',
                            $final >= 75 => 'Exceeds Expectations',
                            $final >= 60 => 'Meets Expectations',
                            default      => 'Needs Improvement',
                        };

                        $questionnaire = $this->seedQuestionnaire($company, $cycle, $report, $supervisor, $hrUser, $compScore);

                        Appraisal::withoutGlobalScopes()->firstOrCreate(
                            ['company_id' => $company->id, 'cycle_id' => $cycle->id, 'employee_id' => $report->id],
                            [
                                'supervisor_id'      => $supervisor->id,
                                'questionnaire_id'   => $questionnaire?->id,
                                'kpi_score'          => $kpiScore,
                                'competency_score'   => $compScore,
                                'kpi_weight'         => 70,
                                'competency_weight'  => 30,
                                'final_score'        => $final,
                                'rating_band'        => $band,
                                'status'             => 'acknowledged',
                                'finalized_at'       => now()->subMonths(2),
                                'acknowledged_at'    => now()->subMonths(1),
                                'supervisor_comment' => 'Good performance overall. Keep up the momentum.',
                                'employee_comment'   => 'I am committed to improving further in the next cycle.',
                            ]
                        );
                    }
                }
            }

            // Recognitions for active cycle
            if ($status === 'active' && $hrUser) {
                $this->seedRecognitions($company, $employees, $hrUser);
            }

            // PIPs for active cycle
            if ($status === 'active') {
                $pipCandidates = $employees->reject(fn ($e) => $e->position?->is_supervisor)->take(2);
                foreach ($pipCandidates as $emp) {
                    $supervisor = $supervisors->firstWhere('id', $emp->primary_supervisor_id) ?? $supervisors->first();
                    if (! $supervisor) {
                        continue;
                    }

                    $pip = Pip::withoutGlobalScopes()->firstOrCreate(
                        ['company_id' => $company->id, 'employee_id' => $emp->id],
                        [
                            'supervisor_id'    => $supervisor->id,
                            'concern'          => 'Consistent failure to meet monthly targets and frequent absenteeism.',
                            'targets'          => "1. Achieve 80% of monthly KPI targets.\n2. Maintain attendance above 90%.\n3. Submit weekly progress reports.",
                            'action_plan'      => "Weekly 1-on-1 coaching sessions with supervisor.\nAttend time management workshop.\nDaily check-ins for first month.",
                            'start_date'       => now()->subMonths(1)->toDateString(),
                            'end_date'         => now()->addMonths(2)->toDateString(),
                            'review_frequency' => 'weekly',
                            'status'           => 'active',
                        ]
                    );

                    CoachingSession::withoutGlobalScopes()->firstOrCreate(
                        ['company_id' => $company->id, 'employee_id' => $emp->id, 'session_date' => now()->subWeeks(2)->toDateString()],
                        [
                            'supervisor_id'      => $supervisor->id,
                            'pip_id'             => $pip->id,
                            'topic'              => 'Performance Improvement Discussion',
                            'goal'               => 'Improve monthly KPI achievement to 80%+',
                            'reality'            => 'Currently achieving 55% of targets. Attendance at 78%.',
                            'options'            => 'Daily planning sessions, workload review, skills training.',
                            'way_forward'        => 'Weekly check-ins, attend Excel training, submit daily reports.',
                            'supervisor_comment' => 'Employee showed willingness to improve. Follow-up in 2 weeks.',
                            'next_meeting_date'  => now()->addWeeks(2)->toDateString(),
                        ]
                    );

                    $this->seedPipCheckins($pip, $hrUser);
                }
            }
        }
    }

    private function seedQuestionnaire(Company $company, Cycle $cycle, Employee $employee, Employee $supervisor, ?User $hrUser, float $compScore): ?Questionnaire
    {
        if (Questionnaire::withoutGlobalScopes()
            ->where('cycle_id', $cycle->id)
            ->where('employee_id', $employee->id)
            ->exists()) {
            return Questionnaire::withoutGlobalScopes()
                ->where('cycle_id', $cycle->id)
                ->where('employee_id', $employee->id)
                ->first();
        }

        $questions = Question::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->get();

        if ($questions->isEmpty()) {
            return null;
        }

        $q = new Questionnaire([
            'cycle_id'              => $cycle->id,
            'employee_id'           => $employee->id,
            'supervisor_id'         => $supervisor->id,
            'title'                 => "{$cycle->name} — Competency Review",
            'due_date'              => $cycle->end_date,
            'status'                => 'completed',
            'self_submitted_at'     => now()->subMonths(3),
            'supervisor_submitted_at' => now()->subMonths(2),
            'assigned_by_user_id'   => $hrUser?->id,
        ]);
        $q->company_id = $company->id;
        $q->save();

        $q->questions()->sync($questions->pluck('id'));

        // Self responses (slightly lower than supervisor)
        $selfBase = max(1, round($compScore / 100 * 5) - 1);
        foreach ($questions as $question) {
            QuestionnaireResponse::create([
                'questionnaire_id' => $q->id,
                'question_id'      => $question->id,
                'rater_type'       => 'self',
                'rating'           => max(1, min(5, $selfBase + rand(-1, 1))),
            ]);
        }

        // Supervisor responses (calibrated to produce the target compScore)
        $supBase = max(1, round($compScore / 100 * 5));
        foreach ($questions as $question) {
            QuestionnaireResponse::create([
                'questionnaire_id' => $q->id,
                'question_id'      => $question->id,
                'rater_type'       => 'supervisor',
                'rating'           => max(1, min(5, $supBase + rand(-1, 1))),
            ]);
        }

        // One comment per category
        $categories = $questions->pluck('category_id')->unique();
        foreach ($categories->take(3) as $catId) {
            foreach (['self', 'supervisor'] as $raterType) {
                QuestionnaireComment::firstOrCreate(
                    ['questionnaire_id' => $q->id, 'category_id' => $catId, 'rater_type' => $raterType],
                    ['comment' => $raterType === 'self'
                        ? 'I believe I have demonstrated consistent effort in this area.'
                        : 'Employee shows good competency here with room for growth.']
                );
            }
        }

        return $q;
    }

    private function seedGoalEvents(Goal $goal, Employee $employee, Employee $supervisor): void
    {
        if (DB::table('perf_goal_events')->where('goal_id', $goal->id)->exists()) {
            return;
        }

        $events = [['cascaded', $supervisor->user_id, $supervisor->id, ['target' => $goal->target]]];

        if ($goal->status !== 'cascaded') {
            $events[] = ['accepted', $employee->user_id, $employee->id, null];
        }
        if (in_array($goal->status, ['submitted', 'approved'])) {
            $events[] = ['submitted', $employee->user_id, $employee->id, ['achieved' => $goal->achieved]];
        }
        if ($goal->status === 'approved') {
            $events[] = ['approved', $supervisor->user_id, $supervisor->id, ['score' => $goal->score]];
        }

        foreach ($events as [$action, $userId, $employeeId, $meta]) {
            DB::table('perf_goal_events')->insert([
                'goal_id'     => $goal->id,
                'user_id'     => $userId,
                'employee_id' => $employeeId,
                'action'      => $action,
                'comment'     => null,
                'meta'        => $meta ? json_encode($meta) : null,
                'created_at'  => now()->subDays(rand(1, 60)),
            ]);
        }
    }

    private function seedRecognitions(Company $company, $employees, User $hrUser): void
    {
        if (DB::table('perf_recognitions')->where('company_id', $company->id)->exists()) {
            return;
        }

        $categories = Recognition::CATEGORIES;
        $messages = [
            'star_performer'   => 'Consistently exceeded targets this quarter. A true asset to the team.',
            'innovation'       => 'Introduced a process improvement that saved significant time and cost.',
            'teamwork'         => 'Went above and beyond to support colleagues during a critical project.',
            'customer_service' => 'Received outstanding feedback from multiple clients this month.',
            'leadership'       => 'Stepped up to lead the team during a challenging period with great results.',
        ];

        foreach ($employees->take(8) as $i => $employee) {
            $category = $categories[$i % count($categories)];
            (new Recognition)->forceFill([
                'company_id'        => $company->id,
                'employee_id'       => $employee->id,
                'awarded_by_user_id'=> $hrUser->id,
                'category'          => $category,
                'message'           => $messages[$category],
                'created_at'        => now()->subDays(rand(5, 90)),
                'updated_at'        => now()->subDays(rand(1, 5)),
            ])->save();
        }
    }

    private function seedPipCheckins(Pip $pip, ?User $hrUser): void
    {
        if (DB::table('perf_pip_checkins')->where('pip_id', $pip->id)->exists()) {
            return;
        }

        $checkins = [
            [3, 'Employee is making steady progress. Attendance has improved to 85%.', 'continue'],
            [2, 'KPI achievement still below target at 65%. Additional support provided.', 'continue'],
            [4, 'Significant improvement noted. KPI achievement now at 78%.', 'complete'],
        ];

        foreach ($checkins as $i => [$rating, $comment, $recommendation]) {
            DB::table('perf_pip_checkins')->insert([
                'pip_id'               => $pip->id,
                'meeting_date'         => now()->subWeeks(4 - $i)->toDateString(),
                'progress_rating'      => $rating,
                'comment'              => $comment,
                'recommendation'       => $recommendation,
                'recorded_by_user_id'  => $hrUser?->id,
                'created_at'           => now()->subWeeks(4 - $i),
                'updated_at'           => now()->subWeeks(4 - $i),
            ]);
        }
    }
}
