<?php

namespace App\Modules\Performance\Services;

use App\Models\Employee;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Questionnaire;
use App\Modules\Performance\Models\QuestionnaireResponse;
use Illuminate\Support\Collection;

/** Overall appraisal result: weighted KPI score + competency score → rating band. */
class AppraisalService
{
    /**
     * KPI scores for many employees at once.
     * Goals count when approved or past due (an overdue, unapproved goal scores 0); future open goals are ignored.
     *
     * @return array<int, float|null> employee_id => score
     */
    public function kpiScores(Cycle $cycle, Collection|array|null $employeeIds = null): array
    {
        $goals = Goal::withoutGlobalScopes()->where('perf_goals.cycle_id', $cycle->id)
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('perf_goals.employee_id', collect($employeeIds)))
            ->where(fn ($q) => $q->where('perf_goals.status', 'approved')->orWhere('perf_goals.due_date', '<', now()->toDateString()))
            ->join('perf_kpis', 'perf_kpis.id', '=', 'perf_goals.kpi_id')
            ->join('perf_objectives', 'perf_objectives.id', '=', 'perf_kpis.objective_id')
            ->get(['perf_goals.employee_id', 'perf_goals.status', 'perf_goals.achievement_pct', 'perf_kpis.objective_id', 'perf_objectives.weight']);

        $scores = [];
        foreach ($goals->groupBy('employee_id') as $employeeId => $rows) {
            $objectives = $rows->groupBy('objective_id')->map(fn ($g) => [
                'weight' => (float) $g->first()->weight,
                'pcts' => $g->map(fn ($r) => $r->status === 'approved' ? (float) $r->achievement_pct : 0.0)->all(),
            ])->values()->all();
            $scores[(int) $employeeId] = ScoreCalculator::weightedKpiScore($objectives);
        }

        return $scores;
    }

    /** @return array<int, float|null> employee_id => competency % (supervisor ratings) */
    public function competencyScores(Cycle $cycle, Collection|array|null $employeeIds = null): array
    {
        $max = SetupService::maxScale($cycle->company_id);
        $rows = QuestionnaireResponse::query()
            ->join('perf_questionnaires', 'perf_questionnaires.id', '=', 'perf_questionnaire_responses.questionnaire_id')
            ->where('perf_questionnaires.cycle_id', $cycle->id)
            ->where('perf_questionnaire_responses.rater_type', 'supervisor')
            ->whereNotNull('perf_questionnaires.supervisor_submitted_at')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('perf_questionnaires.employee_id', collect($employeeIds)))
            ->groupBy('perf_questionnaires.employee_id')
            ->selectRaw('perf_questionnaires.employee_id, AVG(perf_questionnaire_responses.rating) as avg_rating')
            ->pluck('avg_rating', 'employee_id');

        return $rows->map(fn ($avg) => ScoreCalculator::competencyPct((float) $avg, $max))->all();
    }

    /** Computed (not saved) result for one employee. */
    public function preview(Employee $employee, Cycle $cycle): array
    {
        $kpi = $this->kpiScores($cycle, [$employee->id])[$employee->id] ?? null;
        $competency = $this->competencyScores($cycle, [$employee->id])[$employee->id] ?? null;
        $final = ScoreCalculator::finalScore($kpi, $competency, $cycle->kpi_weight, $cycle->competency_weight);

        return [
            'kpi_score' => $kpi, 'competency_score' => $competency,
            'kpi_weight' => $cycle->kpi_weight, 'competency_weight' => $cycle->competency_weight,
            'final_score' => $final, 'rating_band' => ScoreCalculator::band($final, SetupService::bands($cycle->company_id)),
        ];
    }

    /** Appraisal record for an employee in a cycle (created as "pending" on first access). */
    public function recordFor(Employee $employee, Cycle $cycle): Appraisal
    {
        $appraisal = Appraisal::withoutGlobalScopes()->firstOrNew(['cycle_id' => $cycle->id, 'employee_id' => $employee->id]);
        if (! $appraisal->exists) {
            $appraisal->company_id = $employee->company_id;
            $appraisal->fill(['status' => 'pending', 'supervisor_id' => $employee->primary_supervisor_id,
                'kpi_weight' => $cycle->kpi_weight, 'competency_weight' => $cycle->competency_weight]);
            $appraisal->save();
        }

        return $appraisal;
    }

    /** Self-assessment questionnaire for the cycle, created from the active question bank when missing. */
    public function selfQuestionnaire(Employee $employee, Cycle $cycle, ?int $assignedByUserId = null): Questionnaire
    {
        $q = Questionnaire::withoutGlobalScopes()->where('cycle_id', $cycle->id)->where('employee_id', $employee->id)->latest('id')->first();
        if ($q) {
            return $q;
        }
        $q = new Questionnaire([
            'cycle_id' => $cycle->id, 'employee_id' => $employee->id, 'supervisor_id' => $employee->primary_supervisor_id,
            'title' => "{$cycle->name} — Competency Review", 'due_date' => min(now()->addDays(14)->toDateString(), $cycle->end_date->toDateString()),
            'status' => 'pending', 'assigned_by_user_id' => $assignedByUserId,
        ]);
        $q->company_id = $employee->company_id;
        $q->save();
        $q->questions()->sync(\App\Modules\Performance\Models\Question::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)->where('is_active', true)->pluck('id'));

        return $q;
    }

    public function finalize(Appraisal $appraisal, Cycle $cycle, Employee $employee, ?string $comment, ?int $supervisorId): Appraisal
    {
        $result = $this->preview($employee, $cycle);
        $appraisal->fill($result + [
            'supervisor_comment' => $comment, 'supervisor_id' => $supervisorId ?? $appraisal->supervisor_id,
            'status' => 'completed', 'finalized_at' => now(),
        ])->save();

        return $appraisal;
    }
}
