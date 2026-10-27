<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\FocusArea;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\Objective;
use App\Modules\Performance\Models\Plan;
use App\Modules\Performance\Services\GoalWorkflow;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\ScoreCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Scorecards: Plan → Focus Areas → Objectives (weighted) → Activities / KPIs, and KPI assignment. */
class PlanController extends Controller
{
    use PresentsPeople;

    public function __construct(private GoalWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Plan::with(['cycle:id,name,status,start_date,end_date', 'position:id,title', 'employee:id,first_name,last_name', 'creator:id,first_name,last_name', 'creatorUser:id,name'])
            ->withCount(['focusAreas', 'kpis'])->withSum('objectives as total_weight', 'weight')
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')))
            ->when($request->filled('position_id'), fn ($q) => $q->where('position_id', $request->integer('position_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'));

        if (! PerformanceAccess::isAdmin($user)) {
            $me = $user->employee;
            $team = $me ? PerformanceAccess::directReportIds($me->id) : collect();
            $positions = Employee::whereIn('id', $team->push($me?->id))->pluck('position_id')->filter()->unique();
            $query->where(fn ($q) => $q->where('created_by', $me?->id)->orWhereIn('position_id', $positions)
                ->orWhereIn('employee_id', $team));
        }

        return response()->json($query->orderByDesc('id')->paginate(min($request->integer('per_page', 25), 100))
            ->through(fn (Plan $p) => $this->planRow($p)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPlan($request);
        $cycle = Cycle::findOrFail($data['cycle_id']);
        $plan = new Plan($data + ['created_by' => $request->user()->employee?->id, 'created_by_user_id' => $request->user()->id]);
        $plan->company_id = $cycle->company_id;
        $plan->save();

        return response()->json(['data' => $this->planRow($plan->load('cycle', 'position', 'employee', 'creator')), 'message' => 'Assessment plan created.'], 201);
    }

    public function show(Request $request, Plan $plan): JsonResponse
    {
        $plan->load(['cycle', 'position:id,title', 'employee:id,first_name,last_name', 'creator:id,first_name,last_name',
            'focusAreas.objectives.kpis' => fn ($q) => $q->with(['unit', 'frequency', 'questions:id,question,category_id'])
                ->withCount(['goals', 'goals as approved_goals_count' => fn ($g) => $g->where('status', 'approved')])]);

        $myGoals = $request->user()->employee
            ? Goal::where('employee_id', $request->user()->employee->id)->whereIn('kpi_id', $plan->kpis()->pluck('id'))->pluck('id', 'kpi_id')
            : collect();

        return response()->json(['data' => array_merge($this->planRow($plan), [
            'description' => $plan->description,
            'can_edit' => $this->canEdit($request, $plan),
            'focus_areas' => $plan->focusAreas->map(fn ($fa) => [
                'id' => $fa->id, 'name' => $fa->name, 'sort' => $fa->sort,
                'objectives' => $fa->objectives->map(fn ($o) => [
                    'id' => $o->id, 'name' => $o->name, 'weight' => $o->weight, 'sort' => $o->sort,
                    'kpis' => $o->kpis->map(fn ($k) => array_merge($k->toArray(), [
                        'is_percentage' => $k->isPercentage(), 'my_goal_id' => $myGoals[$k->id] ?? null,
                    ])),
                ]),
            ]),
        ])]);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeEdit($request, $plan);
        $plan->update($this->validatedPlan($request, $plan));

        return response()->json(['data' => $this->planRow($plan->load('cycle', 'position', 'employee', 'creator')), 'message' => 'Plan updated.']);
    }

    public function destroy(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeEdit($request, $plan);
        abort_if(Goal::whereIn('kpi_id', $plan->kpis()->pluck('id'))->exists(), 422, 'KPIs in this plan are already assigned to staff.');
        $plan->delete();

        return response()->json(['message' => 'Plan deleted.']);
    }

    /** Set every objective weight at once; they must total exactly 100. */
    public function weights(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeEdit($request, $plan);
        $data = $request->validate(['weights' => ['required', 'array'], 'weights.*' => ['numeric', 'min:0', 'max:100']]);
        $ids = $plan->objectives()->pluck('id');
        $weights = collect($data['weights'])->only($ids->all());
        if ($weights->count() !== $ids->count()) {
            throw ValidationException::withMessages(['weights' => 'Provide a weight for every objective in the plan.']);
        }
        if (! ScoreCalculator::weightsComplete($weights->map(fn ($w) => (float) $w)->all())) {
            throw ValidationException::withMessages(['weights' => 'Objective weights must total 100% (currently '.round($weights->sum(), 2).'%).']);
        }
        DB::transaction(fn () => $weights->each(fn ($w, $id) => Objective::whereKey($id)->update(['weight' => $w])));

        return response()->json(['message' => 'Weights saved.', 'total_weight' => 100]);
    }

    // ---------------------------------------------------------------- focus areas

    public function storeFocusArea(Request $request, Plan $plan): JsonResponse
    {
        $this->authorizeEdit($request, $plan);
        $data = $request->validate(['name' => ['required', 'string', 'max:150']]);
        $fa = $plan->focusAreas()->create($data + ['sort' => $plan->focusAreas()->count()]);

        return response()->json(['data' => $fa, 'message' => 'Focus area added.'], 201);
    }

    public function updateFocusArea(Request $request, FocusArea $focusArea): JsonResponse
    {
        $plan = $this->planOf($focusArea->plan_id);
        $this->authorizeEdit($request, $plan);
        $focusArea->update($request->validate(['name' => ['required', 'string', 'max:150'], 'sort' => ['nullable', 'integer']]));

        return response()->json(['data' => $focusArea, 'message' => 'Focus area updated.']);
    }

    public function destroyFocusArea(Request $request, FocusArea $focusArea): JsonResponse
    {
        $plan = $this->planOf($focusArea->plan_id);
        $this->authorizeEdit($request, $plan);
        abort_if(Goal::whereIn('kpi_id', Kpi::whereIn('objective_id', $focusArea->objectives()->pluck('id'))->pluck('id'))->exists(), 422, 'KPIs under this focus area are assigned to staff.');
        $focusArea->delete();

        return response()->json(['message' => 'Focus area deleted.']);
    }

    // ---------------------------------------------------------------- objectives

    public function storeObjective(Request $request, FocusArea $focusArea): JsonResponse
    {
        $plan = $this->planOf($focusArea->plan_id);
        $this->authorizeEdit($request, $plan);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'weight' => ['required', 'numeric', 'min:0', 'max:100']]);
        $this->assertWeightRoom($plan, (float) $data['weight']);
        $objective = $focusArea->objectives()->create($data + ['plan_id' => $plan->id, 'sort' => $focusArea->objectives()->count()]);

        return response()->json(['data' => $objective, 'message' => 'Objective added.', 'total_weight' => $plan->totalWeight()], 201);
    }

    public function updateObjective(Request $request, Objective $objective): JsonResponse
    {
        $plan = $this->planOf($objective->plan_id);
        $this->authorizeEdit($request, $plan);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:255'], 'weight' => ['sometimes', 'numeric', 'min:0', 'max:100']]);
        if (isset($data['weight'])) {
            $this->assertWeightRoom($plan, (float) $data['weight'], $objective->id);
        }
        $objective->update($data);
        $this->rescoreObjective($objective);

        return response()->json(['data' => $objective, 'message' => 'Objective updated.', 'total_weight' => $plan->totalWeight()]);
    }

    public function destroyObjective(Request $request, Objective $objective): JsonResponse
    {
        $plan = $this->planOf($objective->plan_id);
        $this->authorizeEdit($request, $plan);
        abort_if(Goal::whereIn('kpi_id', $objective->kpis()->pluck('id'))->exists(), 422, 'KPIs under this objective are assigned to staff.');
        $objective->delete();

        return response()->json(['message' => 'Objective deleted.', 'total_weight' => $plan->totalWeight()]);
    }

    // ---------------------------------------------------------------- KPIs

    public function storeKpi(Request $request, Objective $objective): JsonResponse
    {
        $plan = $this->planOf($objective->plan_id);
        $this->authorizeEdit($request, $plan);
        $data = $this->validatedKpi($request, $plan->company_id);
        $plan->cycle?->assertDateWithin($data['due_date'] ?? null);
        $questions = $data['question_ids'] ?? [];
        unset($data['question_ids']);
        $kpi = new Kpi($data + ['plan_id' => $plan->id, 'objective_id' => $objective->id, 'created_by' => $request->user()->employee?->id]);
        $kpi->company_id = $plan->company_id;
        $kpi->save();
        $kpi->questions()->sync($kpi->type === 'qualitative' ? $questions : []);

        return response()->json(['data' => $kpi->load('unit', 'frequency', 'questions'), 'message' => 'KPI added.'], 201);
    }

    public function updateKpi(Request $request, Kpi $kpi): JsonResponse
    {
        $plan = $this->planOf($kpi->plan_id);
        $this->authorizeEdit($request, $plan);
        $data = $this->validatedKpi($request, $plan->company_id, $kpi);
        $plan->cycle?->assertDateWithin($data['due_date'] ?? null);
        $questions = $data['question_ids'] ?? null;
        unset($data['question_ids']);
        $assigned = $kpi->goals()->exists();
        if ($assigned && (isset($data['type']) && $data['type'] !== $kpi->type)) {
            throw ValidationException::withMessages(['type' => 'The KPI type cannot change once it is assigned.']);
        }
        $kpi->update($data);
        if ($questions !== null && ! $assigned) {
            $kpi->questions()->sync($kpi->type === 'qualitative' ? $questions : []);
        }

        return response()->json(['data' => $kpi->load('unit', 'frequency', 'questions'), 'message' => 'KPI updated.']);
    }

    public function destroyKpi(Request $request, Kpi $kpi): JsonResponse
    {
        $this->authorizeEdit($request, $this->planOf($kpi->plan_id));
        abort_if($kpi->goals()->exists(), 422, 'This KPI is assigned to staff. Withdraw the goals first.');
        $kpi->delete();

        return response()->json(['message' => 'KPI deleted.']);
    }

    /** Cascade (distributed / equal) to direct reports, or create a self-owned goal. */
    public function assign(Request $request, Kpi $kpi): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(['cascade', 'self'])],
            'split' => ['nullable', Rule::in(['distributed', 'equal'])],
            'assignees' => ['required_if:mode,cascade', 'array'],
            'assignees.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignees.*.target' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date'],
        ]);
        $goals = $this->workflow->assignFromKpi($kpi, $request->user(), $data['mode'], $data['split'] ?? null, $data['assignees'] ?? [], $data['due_date'] ?? null);

        return response()->json([
            'data' => collect($goals)->map(fn ($g) => ['id' => $g->id, 'employee_id' => $g->employee_id, 'target' => $g->target, 'status' => $g->status]),
            'message' => count($goals) === 1 ? 'KPI assigned.' : 'KPI cascaded to '.count($goals).' employees.',
        ], 201);
    }

    // ---------------------------------------------------------------- helpers

    private function planRow(Plan $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'cycle_id' => $p->cycle_id, 'cycle' => $p->cycle ? ['id' => $p->cycle->id, 'name' => $p->cycle->name, 'status' => $p->cycle->status, 'start_date' => $p->cycle->start_date?->toDateString(), 'end_date' => $p->cycle->end_date?->toDateString()] : null,
            'position_id' => $p->position_id, 'position' => $p->position?->title,
            'employee_id' => $p->employee_id, 'employee' => $p->employee?->full_name,
            'start_date' => $p->start_date?->toDateString(),
            // An admin without an employee record is recorded by user id.
            'created_by' => $p->creator?->full_name ?? $p->creatorUser?->name, 'created_by_id' => $p->created_by,
            'focus_areas_count' => $p->focus_areas_count ?? $p->focusAreas()->count(),
            'kpis_count' => $p->kpis_count ?? $p->kpis()->count(),
            'total_weight' => round((float) ($p->total_weight ?? $p->totalWeight()), 2),
            'created_at' => $p->created_at,
        ];
    }

    private function validatedPlan(Request $request, ?Plan $plan = null): array
    {
        $data = $request->validate([
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:200'],
            'cycle_id' => [$plan ? 'sometimes' : 'required', 'integer', 'exists:perf_cycles,id'],
            'position_id' => ['nullable', 'integer', 'exists:positions,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'start_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($data['position_id']) && ! empty($data['employee_id'])) {
            throw ValidationException::withMessages(['employee_id' => 'Choose a position or an individual employee, not both.']);
        }
        if (isset($data['cycle_id'])) {
            abort_unless(Cycle::whereKey($data['cycle_id'])->exists(), 422, 'Unknown review cycle.');
        }

        return $data;
    }

    private function validatedKpi(Request $request, int $companyId, ?Kpi $kpi = null): array
    {
        $req = $kpi ? 'sometimes' : 'required';
        $type = $request->input('type', $kpi?->type ?? 'quantitative');

        return $request->validate([
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => [$req, Rule::in(['quantitative', 'qualitative'])],
            'kpi_type' => ['nullable', Rule::in(['percentage', 'absolute'])],
            'unit_id' => [$type === 'quantitative' && ! $kpi ? 'required' : 'nullable', 'integer', Rule::exists('perf_units', 'id')->where('company_id', $companyId)],
            'target' => [$type === 'quantitative' && ! $kpi ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'frequency_id' => ['nullable', 'integer', Rule::exists('perf_frequencies', 'id')->where('company_id', $companyId)],
            'is_recurring' => ['boolean'],
            'period_start' => ['nullable', 'date'],
            'due_date' => [$req, 'date'],
            'question_ids' => [$type === 'qualitative' && ! $kpi ? 'required' : 'nullable', 'array'],
            'question_ids.*' => ['integer', Rule::exists('perf_questions', 'id')->where('company_id', $companyId)],
        ], ['question_ids.required' => 'Pick at least one question for a qualitative KPI.']);
    }

    private function assertWeightRoom(Plan $plan, float $weight, ?int $ignoreId = null): void
    {
        $other = (float) $plan->objectives()->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->sum('weight');
        if ($other + $weight - 100 > ScoreCalculator::EPSILON) {
            throw ValidationException::withMessages(['weight' => 'Objective weights in a plan must total 100%. Only '.round(100 - $other, 2).'% is left.']);
        }
    }

    /** Re-score approved goals after an objective's weight changes. */
    private function rescoreObjective(Objective $objective): void
    {
        Goal::whereIn('kpi_id', $objective->kpis()->pluck('id'))->whereNotNull('achieved')->with('kpi.objective', 'cycle')->get()
            ->each(function (Goal $g) {
                $this->workflow->computeScore($g);
                $g->save();
            });
    }

    private function planOf(int $planId): Plan
    {
        return Plan::findOrFail($planId); // company scope applies
    }

    private function canEdit(Request $request, Plan $plan): bool
    {
        $user = $request->user();

        return PerformanceAccess::isAdmin($user) || ($user->employee && $plan->created_by === $user->employee->id);
    }

    private function authorizeEdit(Request $request, Plan $plan): void
    {
        abort_unless($this->canEdit($request, $plan), 403, 'Only the plan owner or HR can change this plan.');
    }
}
