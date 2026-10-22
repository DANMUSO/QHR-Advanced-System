<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Frequency;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Question;
use App\Modules\Performance\Models\QuestionCategory;
use App\Modules\Performance\Models\RatingBand;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Models\Unit;
use App\Modules\Performance\Services\SetupService;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SetupController extends Controller
{
    use PresentsPeople;

    /** Everything the performance screens need for dropdowns. */
    public function index(Request $request): JsonResponse
    {
        if ($companyId = Tenancy::companyId() ?? $request->user()->company_id) {
            SetupService::ensureDefaults($companyId);
        }

        return response()->json([
            'cycles' => $this->cycleRows(),
            'units' => Unit::orderBy('name')->get(),
            'frequencies' => Frequency::orderBy('days')->get(),
            'rating_scale' => RatingScale::orderBy('value')->get(),
            'rating_bands' => RatingBand::orderByDesc('min_score')->get(),
            'categories' => $this->categoryTree(),
            'current_cycle_id' => Cycle::current()?->id,
        ]);
    }

    // ---------------------------------------------------------------- cycles

    public function cycles(): JsonResponse
    {
        return response()->json(['data' => $this->cycleRows()]);
    }

    public function storeCycle(Request $request): JsonResponse
    {
        $cycle = new Cycle($this->validatedCycle($request));
        $cycle->company_id = $this->writeCompanyId($request);
        $cycle->status = 'draft';
        $cycle->save();
        SetupService::ensureDefaults($cycle->company_id);

        return response()->json(['data' => $cycle, 'message' => 'Review cycle created.'], 201);
    }

    public function updateCycle(Request $request, Cycle $cycle): JsonResponse
    {
        $cycle->update($this->validatedCycle($request, $cycle));

        return response()->json(['data' => $cycle, 'message' => 'Review cycle updated.']);
    }

    public function activateCycle(Cycle $cycle): JsonResponse
    {
        abort_if($cycle->status === 'active', 422, 'This cycle is already active.');
        $cycle->update(['status' => 'active']);

        return response()->json(['data' => $cycle, 'message' => "{$cycle->name} is now active."]);
    }

    public function closeCycle(Cycle $cycle): JsonResponse
    {
        abort_unless($cycle->status === 'active', 422, 'Only an active cycle can be closed.');
        $cycle->update(['status' => 'closed']);

        return response()->json(['data' => $cycle, 'message' => "{$cycle->name} has been closed."]);
    }

    public function destroyCycle(Cycle $cycle): JsonResponse
    {
        abort_if(Goal::where('cycle_id', $cycle->id)->exists(), 422, 'This cycle already has goals assigned; close it instead.');
        $cycle->delete();

        return response()->json(['message' => 'Review cycle deleted.']);
    }

    // ---------------------------------------------------------------- units & frequencies

    public function storeUnit(Request $request): JsonResponse
    {
        $companyId = $this->writeCompanyId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('perf_units')->where('company_id', $companyId)],
            'is_percentage' => ['boolean'],
        ]);
        $unit = Unit::create($data + ['company_id' => $companyId]);

        return response()->json(['data' => $unit, 'message' => 'Unit added.'], 201);
    }

    public function updateUnit(Request $request, Unit $unit): JsonResponse
    {
        $unit->update($request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('perf_units')->where('company_id', $unit->company_id)->ignore($unit->id)],
            'is_percentage' => ['boolean'],
        ]));

        return response()->json(['data' => $unit, 'message' => 'Unit updated.']);
    }

    public function destroyUnit(Unit $unit): JsonResponse
    {
        abort_if(DB::table('perf_kpis')->where('unit_id', $unit->id)->exists(), 422, 'This unit is used by KPIs.');
        $unit->delete();

        return response()->json(['message' => 'Unit deleted.']);
    }

    public function storeFrequency(Request $request): JsonResponse
    {
        $companyId = $this->writeCompanyId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('perf_frequencies')->where('company_id', $companyId)],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);
        $frequency = Frequency::create($data + ['company_id' => $companyId]);

        return response()->json(['data' => $frequency, 'message' => 'Frequency added.'], 201);
    }

    public function updateFrequency(Request $request, Frequency $frequency): JsonResponse
    {
        $frequency->update($request->validate([
            'name' => ['required', 'string', 'max:50', Rule::unique('perf_frequencies')->where('company_id', $frequency->company_id)->ignore($frequency->id)],
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]));

        return response()->json(['data' => $frequency, 'message' => 'Frequency updated.']);
    }

    public function destroyFrequency(Frequency $frequency): JsonResponse
    {
        abort_if(DB::table('perf_kpis')->where('frequency_id', $frequency->id)->exists(), 422, 'This frequency is used by KPIs.');
        $frequency->delete();

        return response()->json(['message' => 'Frequency deleted.']);
    }

    // ---------------------------------------------------------------- rating scale & bands

    public function saveScale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scale' => ['required', 'array', 'min:2', 'max:10'],
            'scale.*.value' => ['required', 'integer', 'min:1', 'max:10', 'distinct'],
            'scale.*.label' => ['required', 'string', 'max:60'],
            'scale.*.description' => ['nullable', 'string', 'max:255'],
        ]);
        $companyId = $this->writeCompanyId($request);
        DB::transaction(function () use ($data, $companyId) {
            RatingScale::withoutGlobalScopes()->where('company_id', $companyId)->delete();
            foreach ($data['scale'] as $row) {
                RatingScale::create($row + ['company_id' => $companyId]);
            }
        });

        return response()->json(['data' => RatingScale::orderBy('value')->get(), 'message' => 'Rating scale saved.']);
    }

    public function saveBands(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bands' => ['required', 'array', 'min:2', 'max:10'],
            'bands.*.name' => ['required', 'string', 'max:60', 'distinct'],
            'bands.*.min_score' => ['required', 'numeric', 'min:0', 'max:200'],
            'bands.*.color' => ['nullable', 'string', 'max:20'],
        ]);
        if (! collect($data['bands'])->contains(fn ($b) => (float) $b['min_score'] === 0.0)) {
            throw ValidationException::withMessages(['bands' => 'The lowest band must start at 0.']);
        }
        $companyId = $this->writeCompanyId($request);
        DB::transaction(function () use ($data, $companyId) {
            RatingBand::withoutGlobalScopes()->where('company_id', $companyId)->delete();
            foreach ($data['bands'] as $row) {
                RatingBand::create(['company_id' => $companyId, 'name' => $row['name'], 'min_score' => $row['min_score'], 'color' => $row['color'] ?? 'slate']);
            }
        });

        return response()->json(['data' => RatingBand::orderByDesc('min_score')->get(), 'message' => 'Rating bands saved.']);
    }

    // ---------------------------------------------------------------- question bank

    public function categories(): JsonResponse
    {
        return response()->json(['data' => $this->categoryTree()]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'parent_id' => ['nullable', 'integer', Rule::exists('perf_question_categories', 'id')->whereNull('parent_id')],
        ]);
        $category = new QuestionCategory($data + ['sort' => 99]);
        $category->company_id = $data['parent_id'] ?? null
            ? QuestionCategory::findOrFail($data['parent_id'])->company_id
            : $this->writeCompanyId($request);
        $category->save();

        return response()->json(['data' => $category, 'message' => 'Category added.'], 201);
    }

    public function updateCategory(Request $request, QuestionCategory $category): JsonResponse
    {
        $category->update($request->validate(['name' => ['required', 'string', 'max:150'], 'sort' => ['nullable', 'integer']]));

        return response()->json(['data' => $category, 'message' => 'Category updated.']);
    }

    public function destroyCategory(QuestionCategory $category): JsonResponse
    {
        $ids = $category->children()->pluck('id')->push($category->id);
        abort_if(DB::table('perf_questionnaire_responses')->join('perf_questions', 'perf_questions.id', '=', 'perf_questionnaire_responses.question_id')
            ->whereIn('perf_questions.category_id', $ids)->exists(), 422, 'Questions in this category already have answers; deactivate them instead.');
        $category->delete();

        return response()->json(['message' => 'Category deleted.']);
    }

    public function storeQuestion(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['required', 'integer', 'exists:perf_question_categories,id'],
            'question' => ['required', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        $category = QuestionCategory::findOrFail($data['category_id']);
        $question = new Question($data);
        $question->company_id = $category->company_id;
        $question->save();

        return response()->json(['data' => $question, 'message' => 'Question added.'], 201);
    }

    public function updateQuestion(Request $request, Question $question): JsonResponse
    {
        $question->update($request->validate([
            'category_id' => ['sometimes', 'integer', 'exists:perf_question_categories,id'],
            'question' => ['sometimes', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]));

        return response()->json(['data' => $question, 'message' => 'Question updated.']);
    }

    public function destroyQuestion(Question $question): JsonResponse
    {
        if (DB::table('perf_questionnaire_responses')->where('question_id', $question->id)->exists()
            || DB::table('perf_goal_ratings')->where('question_id', $question->id)->exists()) {
            $question->update(['is_active' => false]);

            return response()->json(['message' => 'The question has answers, so it was deactivated instead of deleted.']);
        }
        $question->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    // ---------------------------------------------------------------- helpers

    private function validatedCycle(Request $request, ?Cycle $cycle = null): array
    {
        $request->merge([
            'start_date' => $request->input('start_date', $request->input('period_start')),
            'end_date' => $request->input('end_date', $request->input('period_end')),
        ]);
        $data = $request->validate([
            'name' => [$cycle ? 'sometimes' : 'required', 'string', 'max:150'],
            'type' => ['nullable', Rule::in(['annual', 'mid_year', 'quarterly', 'probation'])],
            'start_date' => [$cycle ? 'sometimes' : 'required', 'date'],
            'end_date' => [$cycle ? 'sometimes' : 'required', 'date', 'after:start_date'],
            'kpi_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'competency_weight' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_score_pct' => ['nullable', 'numeric', 'min:100', 'max:200'],
        ], [], ['start_date' => 'period start', 'end_date' => 'period end']);
        if (isset($data['kpi_weight']) || isset($data['competency_weight'])) {
            $k = (float) ($data['kpi_weight'] ?? $cycle?->kpi_weight ?? 70);
            $c = (float) ($data['competency_weight'] ?? $cycle?->competency_weight ?? 30);
            if (abs($k + $c - 100) > 0.01) {
                throw ValidationException::withMessages(['kpi_weight' => 'KPI and competency weights must add up to 100%.']);
            }
        }

        return array_filter($data, fn ($v) => $v !== null);
    }

    private function cycleRows()
    {
        $stats = Goal::query()->selectRaw("cycle_id, COUNT(DISTINCT employee_id) as participants, COUNT(*) as total, SUM(status = 'approved') as approved")
            ->groupBy('cycle_id')->get()->keyBy('cycle_id');

        return Cycle::orderByDesc('start_date')->get()->map(fn (Cycle $c) => array_merge($c->toArray(), [
            'period_start' => $c->start_date?->toDateString(),
            'period_end' => $c->end_date?->toDateString(),
            'participants' => (int) ($stats[$c->id]->participants ?? 0),
            'goals_count' => (int) ($stats[$c->id]->total ?? 0),
            'completion_pct' => ($stats[$c->id]->total ?? 0) ? (int) round($stats[$c->id]->approved / $stats[$c->id]->total * 100) : 0,
        ]));
    }

    private function categoryTree()
    {
        return QuestionCategory::whereNull('parent_id')->orderBy('sort')->orderBy('name')
            ->with(['questions', 'children.questions'])->get();
    }
}
