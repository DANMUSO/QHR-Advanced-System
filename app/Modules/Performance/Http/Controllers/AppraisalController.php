<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Notifications\PerformanceNotification;
use App\Modules\Performance\Services\AppraisalService;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\ScoreCalculator;
use App\Modules\Performance\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Overall appraisal per employee per cycle: pending → self_review → manager_review → completed (+ acknowledgement). */
class AppraisalController extends Controller
{
    use PresentsPeople;

    public function __construct(private AppraisalService $service) {}

    public function mine(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $cycleIds = Goal::where('employee_id', $me->id)->distinct()->pluck('cycle_id')
            ->merge(Appraisal::where('employee_id', $me->id)->pluck('cycle_id'))
            ->merge(Cycle::where('status', 'active')->pluck('id'))
            ->unique();
        $cycles = Cycle::whereIn('id', $cycleIds)->orderByDesc('start_date')->get()
            ->filter(fn ($c) => $c->status !== 'draft');

        $rows = $cycles->map(function (Cycle $cycle) use ($me) {
            $appraisal = $this->service->recordFor($me, $cycle);

            return $this->row($appraisal->load('supervisor:id,first_name,last_name'), $cycle, $me, withGoals: true);
        })->values();

        return response()->json(['data' => $rows]);
    }

    /** Employee starts the self-review: returns the self-assessment questionnaire to fill in. */
    public function selfReview(Request $request, Appraisal $appraisal): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($appraisal->employee_id === $me->id, 403);
        abort_if($appraisal->status === 'completed', 422, 'This appraisal is already completed.');
        $cycle = $appraisal->cycle;
        $questionnaire = $this->service->selfQuestionnaire($me, $cycle, $request->user()->id);
        $appraisal->update([
            'questionnaire_id' => $questionnaire->id,
            'status' => $questionnaire->self_submitted_at ? 'manager_review' : 'self_review',
        ]);

        return response()->json([
            'message' => 'Self-review started.', 'questionnaire_id' => $questionnaire->id,
            'data' => $this->row($appraisal->fresh(), $cycle, $me),
        ]);
    }

    public function acknowledge(Request $request, Appraisal $appraisal): JsonResponse
    {
        $me = $this->me($request);
        abort_unless($appraisal->employee_id === $me->id, 403);
        abort_unless($appraisal->status === 'completed', 422, 'Your supervisor has not finalised this appraisal yet.');
        abort_if($appraisal->acknowledged_at, 422, 'Already acknowledged.');
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $appraisal->update(['acknowledged_at' => now(), 'employee_comment' => $data['comment'] ?? null]);
        activity()->performedOn($appraisal)->causedBy($request->user())->log('Appraisal acknowledged');

        return response()->json(['message' => 'Appraisal acknowledged.', 'data' => $this->row($appraisal, $appraisal->cycle, $me)]);
    }

    /** Team (supervisor) or company (HR) appraisal list for a cycle, with live previews. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $cycle = $request->filled('cycle_id') ? Cycle::findOrFail($request->integer('cycle_id')) : Cycle::current();
        if (! $cycle) {
            return response()->json(['data' => [], 'cycle' => null]);
        }
        $teamIds = PerformanceAccess::teamIds($user);
        $employees = Employee::active()->with('position:id,title', 'department:id,name')
            ->when($teamIds !== null, fn ($q) => $q->whereIn('id', $teamIds))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->where(fn ($q) => $q->whereIn('id', Goal::where('cycle_id', $cycle->id)->select('employee_id'))
                ->orWhereIn('id', Appraisal::where('cycle_id', $cycle->id)->select('employee_id')))
            ->orderBy('first_name')->get();

        $ids = $employees->pluck('id');
        $kpi = $this->service->kpiScores($cycle, $ids);
        $comp = $this->service->competencyScores($cycle, $ids);
        $bands = SetupService::bands($cycle->company_id);
        $records = Appraisal::where('cycle_id', $cycle->id)->whereIn('employee_id', $ids)->get()->keyBy('employee_id');

        $rows = $employees->map(function (Employee $e) use ($kpi, $comp, $bands, $records, $cycle) {
            $a = $records[$e->id] ?? null;
            $done = $a && $a->status === 'completed';
            $final = $done ? $a->final_score : ScoreCalculator::finalScore($kpi[$e->id] ?? null, $comp[$e->id] ?? null, $cycle->kpi_weight, $cycle->competency_weight);

            return [
                'id' => $a?->id, 'employee' => $this->person($e), 'employee_name' => $e->full_name,
                'status' => $a?->status ?? 'pending', 'status_label' => Appraisal::STATUS_LABELS[$a?->status ?? 'pending'] ?? null,
                'kpi_score' => $done ? $a->kpi_score : ($kpi[$e->id] ?? null),
                'competency_score' => $done ? $a->competency_score : ($comp[$e->id] ?? null),
                'final_score' => $final,
                'rating_band' => $done ? $a->effectiveBand() : ScoreCalculator::band($final, $bands),
                'is_preview' => ! $done, 'supervisor_comment' => $a?->supervisor_comment,
                'finalized_at' => $a?->finalized_at, 'acknowledged_at' => $a?->acknowledged_at, 'employee_comment' => $a?->employee_comment,
            ];
        });

        return response()->json(['data' => $rows->values(), 'cycle' => $cycle->only(['id', 'name', 'status', 'kpi_weight', 'competency_weight'])]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'cycle_id' => ['required', 'integer', 'exists:perf_cycles,id']]);
        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless(PerformanceAccess::canView($request->user(), $employee->id), 403);

        return response()->json(['data' => $this->service->preview($employee, Cycle::findOrFail($data['cycle_id']))]);
    }

    /** Supervisor (or HR) finalises the result with a summary comment. */
    public function finalize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'cycle_id' => ['required', 'integer', 'exists:perf_cycles,id'],
            'supervisor_comment' => ['required', 'string', 'max:3000'],
        ]);
        $employee = Employee::findOrFail($data['employee_id']);
        abort_unless(PerformanceAccess::canManage($request->user(), $employee->id), 403, 'Only the employee\'s supervisor or HR can finalise the appraisal.');
        $cycle = Cycle::findOrFail($data['cycle_id']);
        $appraisal = $this->service->recordFor($employee, $cycle);
        abort_if($appraisal->acknowledged_at, 422, 'The employee has already acknowledged this appraisal.');
        $this->service->finalize($appraisal, $cycle, $employee, $data['supervisor_comment'], $request->user()->employee?->id);
        activity()->performedOn($appraisal)->causedBy($request->user())->withProperties(['final_score' => $appraisal->final_score, 'band' => $appraisal->rating_band])->log('Appraisal finalised');

        $employee->user?->notify(new PerformanceNotification('Your appraisal is ready',
            "Your {$cycle->name} appraisal was finalised: {$appraisal->rating_band} ({$appraisal->final_score}%). Please review and acknowledge it.", '/performance?tab=appraisals', 'award'));

        return response()->json(['message' => 'Appraisal finalised.', 'data' => $this->row($appraisal, $cycle, $employee)]);
    }

    private function row(Appraisal $a, Cycle $cycle, Employee $employee, bool $withGoals = false): array
    {
        $done = $a->status === 'completed';
        $live = $done ? null : $this->service->preview($employee, $cycle);
        $final = $done ? $a->final_score : $live['final_score'];

        $row = [
            'id' => $a->id, 'cycle_id' => $cycle->id, 'cycle_name' => $cycle->name, 'cycle_status' => $cycle->status,
            'period_start' => $cycle->start_date?->toDateString(), 'period_end' => $cycle->end_date?->toDateString(),
            'status' => $a->status, 'status_label' => Appraisal::STATUS_LABELS[$a->status] ?? $a->status,
            'kpi_score' => $done ? $a->kpi_score : $live['kpi_score'],
            'competency_score' => $done ? $a->competency_score : $live['competency_score'],
            'kpi_weight' => $done ? $a->kpi_weight : $cycle->kpi_weight,
            'competency_weight' => $done ? $a->competency_weight : $cycle->competency_weight,
            'final_score' => $final,
            'overall_score' => $final === null ? null : round(min($final, 100) / 20, 1), // on a 5-point scale
            'rating_band' => $done ? $a->effectiveBand() : $live['rating_band'],
            'is_preview' => ! $done,
            'manager_comment' => $a->supervisor_comment, 'supervisor' => $a->supervisor?->full_name,
            'questionnaire_id' => $a->questionnaire_id,
            'finalized_at' => $a->finalized_at, 'acknowledged_at' => $a->acknowledged_at, 'employee_comment' => $a->employee_comment,
        ];

        if ($withGoals) {
            $row['goals'] = Goal::where('cycle_id', $cycle->id)->where('employee_id', $employee->id)->with('kpi.objective:id,weight')->get()
                ->map(fn ($g) => [
                    'id' => $g->id, 'kpi_name' => $g->kpi?->name, 'status' => $g->status,
                    'achievement_pct' => $g->achievement_pct, 'score' => $g->status === 'approved' ? $g->score : null,
                    'weight' => $g->kpi?->objective?->weight,
                ]);
        }

        return $row;
    }
}
