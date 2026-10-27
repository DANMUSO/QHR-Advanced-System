<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Position;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\CareerPath;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\PotentialRating;
use App\Modules\Performance\Models\SuccessionPlan;
use App\Modules\Performance\Models\TalentPool;
use App\Modules\Performance\Services\AppraisalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Succession & talent: succession plans for critical positions, career paths,
 * talent pools and the 9-box (performance x potential) grid.
 */
class SuccessionController extends Controller
{
    use PresentsPeople;

    // ---- Succession plans --------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $plans = SuccessionPlan::with(['position:id,title', 'incumbent:id,first_name,last_name,photo_path', 'successor:id,first_name,last_name,photo_path'])
            ->latest('id')->get();

        return response()->json(['data' => $plans->map(fn (SuccessionPlan $p) => $this->presentPlan($p))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedPlan($request);
        $position = Position::findOrFail($data['position_id']);
        // The incumbent defaults to the position's current holder (when there is exactly one).
        if (empty($data['incumbent_id'])) {
            $holders = $this->holders($position->id);
            $data['incumbent_id'] = $holders->count() === 1 ? $holders->first()->id : null;
        }

        $plan = new SuccessionPlan($data);
        $plan->company_id = $position->company_id;
        $plan->save();

        return response()->json(['message' => 'Succession plan added.', 'data' => $this->presentPlan($plan->load(['position', 'incumbent', 'successor']))], 201);
    }

    public function update(Request $request, SuccessionPlan $plan): JsonResponse
    {
        $data = $this->validatedPlan($request, $plan);
        if (isset($data['position_id'])) {
            Position::findOrFail($data['position_id']); // must be in the viewer's company
        }
        $plan->update($data);

        return response()->json(['message' => 'Succession plan updated.', 'data' => $this->presentPlan($plan->fresh(['position', 'incumbent', 'successor']))]);
    }

    /** GET /succession/positions/{position}/holders — current holders of a position, to pre-fill the incumbent. */
    public function positionHolders(int $position): JsonResponse
    {
        Position::findOrFail($position);

        return response()->json(['data' => $this->holders($position)->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name])->values()]);
    }

    private function holders(int $positionId)
    {
        return Employee::active()->where('position_id', $positionId)->orderBy('date_of_joining')->get(['id', 'first_name', 'last_name']);
    }

    public function destroy(SuccessionPlan $plan): JsonResponse
    {
        $plan->delete();

        return response()->json(['message' => 'Succession plan removed.']);
    }

    // ---- Career paths ------------------------------------------------------------------------

    public function careers(): JsonResponse
    {
        $paths = CareerPath::with(['fromPosition:id,title', 'toPosition:id,title'])->orderBy('name')->get();
        $onPath = Employee::active()->whereIn('position_id', $paths->pluck('from_position_id')->unique())
            ->selectRaw('position_id, count(*) as c')->groupBy('position_id')->pluck('c', 'position_id');

        return response()->json(['data' => $paths->map(fn (CareerPath $c) => $this->presentCareer($c, (int) ($onPath[$c->from_position_id] ?? 0)))->values()]);
    }

    public function storeCareer(Request $request): JsonResponse
    {
        $data = $this->validatedCareer($request);
        $from = Position::findOrFail($data['from_position_id']);

        $path = new CareerPath($data);
        $path->company_id = $from->company_id;
        $path->save();

        return response()->json(['message' => 'Career path added.', 'data' => $this->presentCareer($path->load(['fromPosition', 'toPosition']))], 201);
    }

    public function updateCareer(Request $request, CareerPath $careerPath): JsonResponse
    {
        $careerPath->update($this->validatedCareer($request));

        return response()->json(['message' => 'Career path updated.', 'data' => $this->presentCareer($careerPath->fresh(['fromPosition', 'toPosition']))]);
    }

    public function destroyCareer(CareerPath $careerPath): JsonResponse
    {
        $careerPath->delete();

        return response()->json(['message' => 'Career path removed.']);
    }

    // ---- Talent pools ------------------------------------------------------------------------

    public function pools(): JsonResponse
    {
        $pools = TalentPool::withCount('members')
            ->with(['members' => fn ($q) => $q->select('employees.id', 'first_name', 'last_name', 'photo_path')])
            ->orderBy('name')->get();

        return response()->json(['data' => $pools->map(fn (TalentPool $p) => $this->presentPool($p))->values()]);
    }

    public function storePool(Request $request): JsonResponse
    {
        $data = $this->validatedPool($request);
        $pool = new TalentPool(collect($data)->except('employee_ids')->all());
        $pool->company_id = $this->writeCompanyId($request);
        $pool->save();
        $this->syncMembers($pool, $data['employee_ids'] ?? null);

        return response()->json(['message' => 'Talent pool created.', 'data' => $this->presentPool($pool->loadCount('members')->load('members'))], 201);
    }

    public function updatePool(Request $request, TalentPool $talentPool): JsonResponse
    {
        $data = $this->validatedPool($request, $talentPool);
        $talentPool->update(collect($data)->except('employee_ids')->all());
        $this->syncMembers($talentPool, $data['employee_ids'] ?? null);

        return response()->json(['message' => 'Talent pool updated.', 'data' => $this->presentPool($talentPool->loadCount('members')->load('members'))]);
    }

    public function destroyPool(TalentPool $talentPool): JsonResponse
    {
        $talentPool->delete();

        return response()->json(['message' => 'Talent pool removed.']);
    }

    // ---- 9-box -------------------------------------------------------------------------------

    /**
     * Employees placed on the grid for a cycle. Performance comes from the finalised appraisal score, or — before
     * appraisals are finalised — from the KPI score of the employee's approved results in the cycle. Potential comes
     * from potential ratings; anyone with a performance score but no potential rating is listed under
     * "potential_not_rated" (with the action to rate them) instead of being placed on a guessed box.
     */
    public function nineBox(Request $request, AppraisalService $appraisals): JsonResponse
    {
        $request->validate(['cycle_id' => ['required', 'integer']]);
        $cycle = Cycle::findOrFail($request->integer('cycle_id'));

        $potential = PotentialRating::where('cycle_id', $cycle->id)->get()->keyBy('employee_id');
        $finals = Appraisal::where('cycle_id', $cycle->id)->whereNotNull('final_score')->pluck('final_score', 'employee_id');
        $withResults = Goal::where('cycle_id', $cycle->id)->where('status', 'approved')->distinct()->pluck('employee_id');
        $kpi = $withResults->isEmpty() ? [] : $appraisals->kpiScores($cycle, $withResults);

        $scores = collect($kpi)->filter(fn ($s) => $s !== null)->map(fn ($s) => ['score' => (float) $s, 'source' => 'kpi']);
        foreach ($finals as $employeeId => $final) {
            $scores[(int) $employeeId] = ['score' => (float) $final, 'source' => 'appraisal'];
        }
        $employees = Employee::whereIn('id', $scores->keys())->with('position:id,title')->get(['id', 'first_name', 'last_name', 'photo_path', 'position_id']);

        $rows = $employees->map(function (Employee $e) use ($scores, $potential) {
            $score = $scores[$e->id]['score'];
            $rating = $potential[$e->id] ?? null;

            return [
                'id' => $e->id, 'name' => $e->full_name, 'photo_url' => $e->photo_url, 'position' => $e->position?->title,
                'final_score' => round($score, 1), 'score_source' => $scores[$e->id]['source'],
                'performance_box' => $score >= 85 ? 3 : ($score >= 70 ? 2 : 1),
                'potential_box' => $rating ? max(1, min(3, (int) $rating->potential)) : null,
                'potential_rated' => (bool) $rating,
                'potential_note' => $rating?->note,
            ];
        })->sortBy('name')->values();

        return response()->json([
            'data' => $rows->where('potential_rated', true)->values(),
            'potential_not_rated' => $rows->where('potential_rated', false)->values(),
        ]);
    }

    /** PUT /succession/9box/potential {cycle_id, employee_id, potential 1–3, note?} — rate (or re-rate) potential for the 9-box. */
    public function ratePotential(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cycle_id' => ['required', 'integer'],
            'employee_id' => ['required', 'integer'],
            'potential' => ['required', 'integer', 'min:1', 'max:3'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $cycle = Cycle::findOrFail($data['cycle_id']);
        $employee = Employee::where('company_id', $cycle->company_id)->findOrFail($data['employee_id']);

        $rating = PotentialRating::withoutGlobalScopes()->firstOrNew(['cycle_id' => $cycle->id, 'employee_id' => $employee->id]);
        $rating->company_id = $cycle->company_id;
        $rating->fill(['potential' => $data['potential'], 'note' => $data['note'] ?? $rating->note, 'rated_by_user_id' => $request->user()->id])->save();

        return response()->json(['message' => "Potential rated for {$employee->full_name}.", 'data' => $rating]);
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function validatedPlan(Request $request, ?SuccessionPlan $plan = null): array
    {
        $req = $plan ? 'sometimes' : 'required';

        return $request->validate([
            'position_id' => [$req, 'integer', 'exists:positions,id'],
            'incumbent_id' => ['nullable', 'integer', 'exists:employees,id'],
            'successor_id' => ['nullable', 'integer', 'exists:employees,id', 'different:incumbent_id'],
            'readiness' => ['sometimes', Rule::in(SuccessionPlan::READINESS)],
            'risk_level' => ['sometimes', Rule::in(SuccessionPlan::RISK)],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function validatedCareer(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'from_position_id' => ['required', 'integer', 'exists:positions,id'],
            'to_position_id' => ['required', 'integer', 'exists:positions,id', 'different:from_position_id'],
            'min_years' => ['nullable', 'integer', 'min:0', 'max:40'],
            'requirements' => ['nullable', 'string', 'max:3000'],
        ]);
        $data['min_years'] ??= 2; // column is NOT NULL

        return $data;
    }

    private function validatedPool(Request $request, ?TalentPool $pool = null): array
    {
        return $request->validate([
            'name' => [$pool ? 'sometimes' : 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'criteria' => ['nullable', 'string', 'max:2000'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
        ]);
    }

    private function syncMembers(TalentPool $pool, ?array $ids): void
    {
        if ($ids === null) {
            return;
        }
        $ids = Employee::whereIn('id', $ids)->where('company_id', $pool->company_id)->pluck('id');
        $pool->members()->syncWithPivotValues($ids, ['company_id' => $pool->company_id]);
        activity()->performedOn($pool)->withProperties(['employee_ids' => $ids->all()])->log('Talent pool members updated');
    }

    private function presentPlan(SuccessionPlan $p): array
    {
        return [
            'id' => $p->id,
            'company_id' => $p->company_id,
            'position_id' => $p->position_id,
            'position_title' => $p->position?->title,
            'incumbent_id' => $p->incumbent_id,
            'incumbent_name' => $p->incumbent?->full_name,
            'successor_id' => $p->successor_id,
            'successor_name' => $p->successor?->full_name,
            'readiness' => $p->readiness,
            'risk_level' => $p->risk_level,
            'notes' => $p->notes,
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function presentCareer(CareerPath $c, int $onPath = 0): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'from_position_id' => $c->from_position_id,
            'from_position' => $c->fromPosition?->title,
            'to_position_id' => $c->to_position_id,
            'to_position' => $c->toPosition?->title,
            'min_years' => $c->min_years,
            'requirements' => $c->requirements,
            'employees_on_path' => $onPath,
        ];
    }

    private function presentPool(TalentPool $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'criteria' => $p->criteria,
            'members_count' => (int) ($p->members_count ?? 0),
            'members' => $p->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->full_name, 'photo_url' => $m->photo_url])->values(),
        ];
    }
}
