<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Pip;
use App\Modules\Performance\Models\Questionnaire;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Performance\Services\AppraisalService;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\ScoreCalculator;
use App\Modules\Performance\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use PresentsPeople;

    public function __construct(private AppraisalService $service) {}

    /** Overall performance per employee + progress, ranking, top performers and rating distribution. */
    public function index(Request $request): JsonResponse
    {
        $cycle = $this->cycle($request);
        if (! $cycle) {
            return response()->json(['data' => [], 'summary' => null]);
        }
        $rows = $this->employeeRows($request, $cycle);
        $goals = Goal::where('cycle_id', $cycle->id)->whereIn('employee_id', $rows->pluck('employee_id'));

        $departments = $rows->groupBy('department')->map(fn ($g, $name) => [
            'department' => $name ?: 'Unassigned', 'employees' => $g->count(),
            'avg_score' => round((float) $g->whereNotNull('final_score')->avg('final_score'), 1),
            'completion_pct' => $g->sum('goals_count') ? (int) round($g->sum('approved') / $g->sum('goals_count') * 100) : 0,
        ])->sortByDesc('avg_score')->values();

        $bands = array_keys(SetupService::bands($cycle->company_id));

        return response()->json([
            'data' => $rows->values(),
            'cycle' => $cycle->only(['id', 'name', 'status']),
            'summary' => [
                'employees' => $rows->count(),
                'avg_score' => round((float) $rows->whereNotNull('final_score')->avg('final_score'), 1),
                'status_counts' => collect(Goal::STATUSES)->mapWithKeys(fn ($s) => [$s => (clone $goals)->where('status', $s)->count()]),
                'departments' => $departments,
                'top_performers' => $rows->whereNotNull('final_score')->sortByDesc('final_score')->take(10)->values(),
                'distribution' => collect($bands)->map(fn ($b) => ['band' => $b, 'count' => $rows->where('overall_rating', $b)->count()])->values(),
                'not_accepted' => (clone $goals)->where('status', 'cascaded')->with('employee:id,first_name,last_name,department_id', 'employee.department:id,name', 'kpi:id,name', 'assigner:id,first_name,last_name')
                    ->orderBy('created_at')->limit(200)->get()->map(fn ($g) => [
                        'id' => $g->id, 'employee_name' => $g->employee?->full_name, 'department' => $g->employee?->department?->name,
                        'kpi' => $g->kpi?->name, 'assigned_by' => $g->assigner?->full_name, 'assigned_at' => $g->created_at,
                        'days_pending' => (int) $g->created_at->diffInDays(now()), 'due_date' => $g->due_date->toDateString(),
                    ]),
            ],
        ]);
    }

    /** CSV export: per-goal detail (default) or per-employee summary (?detail=summary). */
    public function export(Request $request): StreamedResponse
    {
        $cycle = $this->cycle($request);
        abort_unless($cycle, 422, 'Choose a review cycle.');
        $filename = 'performance-'.str($cycle->name)->slug().'-'.now()->format('Ymd').'.csv';

        if ($request->query('detail') === 'summary') {
            $rows = $this->employeeRows($request, $cycle);
            $header = ['Employee No', 'Employee', 'Department', 'Position', 'Supervisor', 'Goals', 'Approved', 'Not accepted', 'Under review', 'Awaiting approval', 'KPI score %', 'Competency %', 'Final score %', 'Rating', 'Appraisal status'];
            $lines = $rows->map(fn ($r) => [$r['employee_number'], $r['employee_name'], $r['department'], $r['position'], $r['supervisor'], $r['goals_count'], $r['approved'],
                $r['not_accepted'], $r['under_review'], $r['not_approved'], $r['avg_score'], $r['competency_score'], $r['final_score'], $r['overall_rating'], $r['appraisal_status']]);
        } else {
            $header = ['Employee No', 'Employee', 'Department', 'Location', 'Supervisor', 'Focus area', 'Objective', 'Weight %', 'Activity / KPI', 'Unit', 'Target', 'Achieved', 'Achievement %', 'Score', 'Status', 'Due date', 'Employee comment', 'Supervisor comment'];
            $lines = $this->goalQuery($request, $cycle)->with(['employee.department:id,name', 'employee.branch:id,name', 'employee.primarySupervisor:id,first_name,last_name', 'kpi.unit', 'kpi.objective.focusArea'])
                ->orderBy('employee_id')->get()->map(fn ($g) => [
                    $g->employee?->employee_number, $g->employee?->full_name, $g->employee?->department?->name, $g->employee?->branch?->name,
                    $g->employee?->primarySupervisor?->full_name, $g->kpi?->objective?->focusArea?->name, $g->kpi?->objective?->name, $g->kpi?->objective?->weight,
                    $g->kpi?->name, $g->kpi?->unit?->name, $g->target, $g->achieved, $g->achievement_pct, $g->score, str_replace('_', ' ', $g->status),
                    $g->due_date?->toDateString(), $g->employee_comment, $g->supervisor_comment,
                ]);
        }

        return response()->streamDownload(function () use ($header, $lines) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($lines as $line) {
                fputcsv($out, $line);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Calibration: finalised appraisals with the supervisor's band and any calibrated band. */
    public function calibration(Request $request): JsonResponse
    {
        $cycle = $this->cycle($request);
        if (! $cycle) {
            return response()->json(['data' => []]);
        }
        $rows = Appraisal::where('cycle_id', $cycle->id)->where('status', 'completed')
            ->with(['employee.department:id,name', 'employee.position:id,title', 'supervisor:id,first_name,last_name'])
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->integer('department_id'))))
            ->get()->sortByDesc('final_score')->values();
        $users = \App\Models\User::whereIn('id', $rows->pluck('calibrated_by_user_id')->filter())->pluck('name', 'id');
        $bands = array_keys(SetupService::bands($cycle->company_id));

        return response()->json([
            'data' => $rows->map(fn (Appraisal $a) => [
                'id' => $a->id, 'employee' => $this->person($a->employee), 'employee_name' => $a->employee?->full_name,
                'department' => $a->employee?->department?->name, 'supervisor' => $a->supervisor?->full_name,
                'score' => $a->final_score, 'kpi_score' => $a->kpi_score, 'competency_score' => $a->competency_score,
                'manager_rating' => $a->rating_band, 'calibrated_rating' => $a->calibrated_band,
                'calibrated_by' => $a->calibrated_by_user_id ? ($users[$a->calibrated_by_user_id] ?? null) : null,
                'calibrated_at' => $a->calibrated_at, 'calibration_note' => $a->calibration_note,
            ]),
            'bands' => $bands,
            'distribution' => collect($bands)->map(fn ($b) => [
                'band' => $b, 'manager' => $rows->where('rating_band', $b)->count(),
                'calibrated' => $rows->filter(fn ($a) => $a->effectiveBand() === $b)->count(),
            ]),
        ]);
    }

    public function calibrate(Request $request, Appraisal $appraisal): JsonResponse
    {
        abort_unless($appraisal->status === 'completed', 422, 'Only finalised appraisals can be calibrated.');
        $bands = array_keys(SetupService::bands($appraisal->company_id));
        $data = $request->validate([
            'calibrated_rating' => ['nullable', Rule::in($bands)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $appraisal->update([
            'calibrated_band' => $data['calibrated_rating'] ?? null, 'calibration_note' => $data['note'] ?? null,
            'calibrated_by_user_id' => $request->user()->id, 'calibrated_at' => now(),
        ]);
        activity()->performedOn($appraisal)->causedBy($request->user())->withProperties($data)->log('Appraisal calibrated');

        return response()->json(['message' => 'Calibration saved.']);
    }

    /** Performance tab on the employee profile. */
    public function employeeSummary(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(PerformanceAccess::canView($request->user(), $employee->id), 403);
        $cycles = Cycle::whereIn('id', Goal::where('employee_id', $employee->id)->distinct()->pluck('cycle_id'))->orderByDesc('start_date')->get();
        $appraisals = Appraisal::where('employee_id', $employee->id)->get()->keyBy('cycle_id');

        $history = $cycles->map(function (Cycle $c) use ($employee, $appraisals) {
            $a = $appraisals[$c->id] ?? null;
            $live = $a?->status === 'completed' ? null : $this->service->preview($employee, $c);
            $goals = Goal::where('cycle_id', $c->id)->where('employee_id', $employee->id)->get(['status']);

            return [
                'cycle' => $c->only(['id', 'name', 'status']),
                'goals' => $goals->count(), 'approved' => $goals->where('status', 'approved')->count(),
                'kpi_score' => $a?->status === 'completed' ? $a->kpi_score : $live['kpi_score'],
                'competency_score' => $a?->status === 'completed' ? $a->competency_score : $live['competency_score'],
                'final_score' => $a?->status === 'completed' ? $a->final_score : $live['final_score'],
                'rating_band' => $a?->status === 'completed' ? $a->effectiveBand() : $live['rating_band'],
                'finalized' => $a?->status === 'completed', 'acknowledged_at' => $a?->acknowledged_at,
            ];
        });
        $current = Cycle::current();

        return response()->json(['data' => [
            'history' => $history,
            'current_goals' => $current ? Goal::where('employee_id', $employee->id)->where('cycle_id', $current->id)->with('kpi:id,name,unit_id', 'kpi.unit:id,name')
                ->orderBy('due_date')->get()->map(fn ($g) => [
                    'id' => $g->id, 'kpi' => $g->kpi?->name, 'unit' => $g->kpi?->unit?->name, 'status' => $g->status, 'target' => $g->target,
                    'achieved' => $g->achieved, 'achievement_pct' => $g->achievement_pct, 'due_date' => $g->due_date->toDateString(),
                    'progress_pct' => $g->target > 0 && $g->achieved !== null ? min(100, round($g->achieved / $g->target * 100)) : 0,
                ]) : [],
            'reviews' => Questionnaire::where('employee_id', $employee->id)->latest('due_date')->limit(5)->get(['id', 'title', 'status', 'due_date']),
            'active_pip' => Pip::where('employee_id', $employee->id)->whereIn('status', ['active', 'extended'])->first(['id', 'start_date', 'end_date', 'status']),
            'recognitions' => Recognition::where('employee_id', $employee->id)->count(),
        ]]);
    }

    // ---------------------------------------------------------------- helpers

    private function cycle(Request $request): ?Cycle
    {
        return $request->filled('cycle_id') ? Cycle::findOrFail($request->integer('cycle_id')) : Cycle::current();
    }

    private function employeeFilter(Request $request)
    {
        $user = $request->user();
        $teamIds = PerformanceAccess::teamIds($user);

        return fn ($q) => $q->when($teamIds !== null, fn ($q) => $q->whereIn('employees.id', $teamIds))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('company_id') && \App\Support\Tenancy::isGlobal(), fn ($q) => $q->where('employees.company_id', $request->integer('company_id')));
    }

    private function goalQuery(Request $request, Cycle $cycle)
    {
        return Goal::where('cycle_id', $cycle->id)->whereHas('employee', $this->employeeFilter($request));
    }

    private function employeeRows(Request $request, Cycle $cycle): Collection
    {
        $goals = $this->goalQuery($request, $cycle)->get(['employee_id', 'status']);
        $ids = $goals->pluck('employee_id')->unique()->values();
        $employees = Employee::withTrashed()->whereIn('id', $ids)->with('department:id,name', 'position:id,title', 'primarySupervisor:id,first_name,last_name')->get();
        $kpi = $this->service->kpiScores($cycle, $ids);
        $comp = $this->service->competencyScores($cycle, $ids);
        $appraisals = Appraisal::where('cycle_id', $cycle->id)->whereIn('employee_id', $ids)->get()->keyBy('employee_id');
        $bands = SetupService::bands($cycle->company_id);

        return $employees->map(function (Employee $e) use ($goals, $kpi, $comp, $appraisals, $bands, $cycle) {
            $g = $goals->where('employee_id', $e->id);
            $a = $appraisals[$e->id] ?? null;
            $done = $a?->status === 'completed';
            $final = $done ? $a->final_score : ScoreCalculator::finalScore($kpi[$e->id] ?? null, $comp[$e->id] ?? null, $cycle->kpi_weight, $cycle->competency_weight);

            return [
                'id' => $e->id, 'employee_id' => $e->id, 'employee_name' => $e->full_name, 'employee_number' => $e->employee_number, 'photo_url' => $e->photo_url,
                'department' => $e->department?->name, 'position' => $e->position?->title, 'supervisor' => $e->primarySupervisor?->full_name,
                'goals_count' => $g->count(), 'approved' => $g->where('status', 'approved')->count(),
                'not_done' => $g->where('status', 'in_progress')->count(), 'not_accepted' => $g->where('status', 'cascaded')->count(),
                'not_approved' => $g->where('status', 'submitted')->count(), 'under_review' => $g->where('status', 'under_review')->count(),
                'avg_score' => $done ? $a->kpi_score : ($kpi[$e->id] ?? null),
                'competency_score' => $done ? $a->competency_score : ($comp[$e->id] ?? null),
                'final_score' => $final,
                'overall_rating' => $done ? $a->effectiveBand() : ScoreCalculator::band($final, $bands),
                'appraisal_status' => $a ? (Appraisal::STATUS_LABELS[$a->status] ?? $a->status) : 'Not started',
            ];
        })->sortBy('employee_name')->values();
    }
}
