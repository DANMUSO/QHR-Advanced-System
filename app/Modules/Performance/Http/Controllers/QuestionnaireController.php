<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Frequency;
use App\Modules\Performance\Models\Question;
use App\Modules\Performance\Models\Questionnaire;
use App\Modules\Performance\Models\QuestionnaireComment;
use App\Modules\Performance\Models\QuestionnaireResponse;
use App\Modules\Performance\Models\RatingScale;
use App\Modules\Performance\Notifications\PerformanceNotification;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\SetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Competency / 360° questionnaires: self rating + supervisor rating of the same questions. */
class QuestionnaireController extends Controller
{
    use PresentsPeople;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $me = $user->employee;
        $scope = $request->query('scope');

        $query = Questionnaire::with(['employee.position:id,title', 'employee.department:id,name', 'supervisor:id,first_name,last_name', 'cycle:id,name'])
            ->withCount([
                'responses as self_answers' => fn ($q) => $q->where('rater_type', 'self'),
                'responses as supervisor_answers' => fn ($q) => $q->where('rater_type', 'supervisor'),
                'questions',
            ])
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')));

        if ($scope === 'mine') {
            $query->where('employee_id', $me?->id);
        } elseif ($scope === 'to_rate') {
            $query->where('supervisor_id', $me?->id);
        } elseif (! PerformanceAccess::isAdmin($user)) {
            $tree = $me ? PerformanceAccess::subordinateIds($me->id) : collect();
            $query->where(fn ($q) => $q->where('employee_id', $me?->id)->orWhere('supervisor_id', $me?->id)->orWhereIn('employee_id', $tree));
        }

        $rows = $query->orderByRaw("CASE status WHEN 'pending' THEN 1 WHEN 'self_submitted' THEN 2 WHEN 'supervisor_submitted' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END")->orderBy('due_date')
            ->paginate(min($request->integer('per_page', 50), 200));

        return response()->json($rows->through(fn (Questionnaire $q) => $this->row($q, $request)));
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['employee_id' => $request->input('employee_id', $request->input('subject_id'))]);
        $data = $request->validate([
            'employee_id' => ['required'],
            'cycle_id' => ['nullable', 'integer', 'exists:perf_cycles,id'],
            'due_date' => ['required', 'date', 'after_or_equal:today'],
            'title' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:200'],
            'frequency_id' => ['nullable', 'integer', 'exists:perf_frequencies,id'],
            'supervisor_id' => ['nullable', 'integer', 'exists:employees,id'],
            'question_ids' => ['nullable', 'array'],
            'question_ids.*' => ['integer', 'exists:perf_questions,id'],
        ], [], ['employee_id' => 'employee']);

        $employee = $this->findEmployee($data['employee_id']);
        abort_unless(PerformanceAccess::canManage($request->user(), $employee->id), 403, 'You can only assign reviews to your team.');
        $cycle = ! empty($data['cycle_id']) ? Cycle::findOrFail($data['cycle_id']) : Cycle::current($employee->company_id);
        abort_unless($cycle, 422, 'Create a review cycle first.');
        SetupService::ensureDefaults($employee->company_id);

        $questionIds = collect($data['question_ids'] ?? [])->whenEmpty(fn () => Question::withoutGlobalScopes()
            ->where('company_id', $employee->company_id)->where('is_active', true)->pluck('id'));
        abort_if($questionIds->isEmpty(), 422, 'The question bank is empty.');

        $q = new Questionnaire([
            'cycle_id' => $cycle->id, 'employee_id' => $employee->id,
            'supervisor_id' => $data['supervisor_id'] ?? $employee->primary_supervisor_id,
            'title' => $data['title'] ?? $data['notes'] ?? "{$cycle->name} — Competency Review",
            'due_date' => $data['due_date'], 'frequency_id' => $data['frequency_id'] ?? null,
            'status' => 'pending', 'assigned_by_user_id' => $request->user()->id,
        ]);
        $q->company_id = $employee->company_id;
        $q->save();
        $q->questions()->sync($questionIds);

        Appraisal::withoutGlobalScopes()->where('cycle_id', $cycle->id)->where('employee_id', $employee->id)->whereNull('questionnaire_id')
            ->update(['questionnaire_id' => $q->id]);

        $employee->user?->notify(new PerformanceNotification('Competency review assigned',
            "Please complete your self-assessment \"{$q->title}\" by {$q->due_date->format('d M Y')}.", "/performance/reviews/{$q->id}", 'clipboard-check'));

        return response()->json(['data' => $this->row($q->load('employee', 'supervisor', 'cycle')->loadCount('questions'), $request), 'message' => 'Review assigned.'], 201);
    }

    public function show(Request $request, Questionnaire $questionnaire): JsonResponse
    {
        $role = $this->role($request, $questionnaire);
        abort_unless($role || PerformanceAccess::canView($request->user(), $questionnaire->employee_id), 403);

        $questionnaire->load(['employee.position:id,title', 'employee.department:id,name', 'supervisor:id,first_name,last_name',
            'cycle:id,name', 'questions.category.parent', 'responses', 'comments']);
        $max = SetupService::maxScale($questionnaire->company_id);
        // The employee sees supervisor ratings only once the supervisor has submitted.
        $hideSupervisor = $role === 'self' && ! $questionnaire->supervisor_submitted_at;
        $responses = $questionnaire->responses->reject(fn ($r) => $hideSupervisor && $r->rater_type === 'supervisor');
        $hideSelf = $role === 'supervisor' && ! $questionnaire->self_submitted_at && ! PerformanceAccess::isAdmin($request->user());
        $responses = $responses->reject(fn ($r) => $hideSelf && $r->rater_type === 'self');

        $categories = $questionnaire->questions->groupBy(fn ($q) => $q->category->rootId())->map(function ($questions) use ($responses, $questionnaire) {
            $root = $questions->first()->category->parent ?? $questions->first()->category;
            $ids = $questions->pluck('id');
            $self = $responses->where('rater_type', 'self')->whereIn('question_id', $ids);
            $sup = $responses->where('rater_type', 'supervisor')->whereIn('question_id', $ids);

            return [
                'id' => $root->id, 'name' => $root->name,
                'self_avg' => $self->count() ? round($self->avg('rating'), 2) : null,
                'supervisor_avg' => $sup->count() ? round($sup->avg('rating'), 2) : null,
                'comments' => [
                    'self' => $questionnaire->comments->where('category_id', $root->id)->firstWhere('rater_type', 'self')?->comment,
                    'supervisor' => $questionnaire->comments->where('category_id', $root->id)->firstWhere('rater_type', 'supervisor')?->comment,
                ],
                'questions' => $questions->map(fn ($q) => [
                    'id' => $q->id, 'question' => $q->question,
                    'sub_category' => $q->category->parent_id ? $q->category->name : null,
                    'self' => $self->firstWhere('question_id', $q->id)?->rating,
                    'supervisor' => $sup->firstWhere('question_id', $q->id)?->rating,
                ])->values(),
            ];
        })->values();

        return response()->json(['data' => $this->row($questionnaire->loadCount('questions'), $request) + [
            'categories' => $categories,
            'scale' => RatingScale::withoutGlobalScopes()->where('company_id', $questionnaire->company_id)->orderBy('value')->get(['value', 'label']),
            'max_scale' => $max,
        ]]);
    }

    public function respond(Request $request, Questionnaire $questionnaire): JsonResponse
    {
        $role = $this->role($request, $questionnaire);
        abort_unless($role, 403, 'You are not a rater on this review.');
        $data = $request->validate([
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['nullable', 'integer', 'min:1', 'max:'.SetupService::maxScale($questionnaire->company_id)],
            'comments' => ['nullable', 'array'],
            'comments.*' => ['nullable', 'string', 'max:3000'],
            'submit' => ['boolean'],
        ]);
        $submittedField = $role === 'self' ? 'self_submitted_at' : 'supervisor_submitted_at';
        abort_if($questionnaire->{$submittedField}, 422, 'You have already submitted this review.');

        $questionIds = $questionnaire->questions()->pluck('perf_questions.id');
        $ratings = collect($data['ratings'] ?? [])->filter(fn ($r) => $r !== null)->only($questionIds->all());

        DB::transaction(function () use ($questionnaire, $role, $ratings, $data) {
            foreach ($ratings as $qid => $rating) {
                QuestionnaireResponse::updateOrCreate(
                    ['questionnaire_id' => $questionnaire->id, 'question_id' => $qid, 'rater_type' => $role], ['rating' => $rating]);
            }
            foreach ($data['comments'] ?? [] as $catId => $comment) {
                if (blank($comment)) {
                    QuestionnaireComment::where(['questionnaire_id' => $questionnaire->id, 'category_id' => $catId, 'rater_type' => $role])->delete();

                    continue;
                }
                QuestionnaireComment::updateOrCreate(
                    ['questionnaire_id' => $questionnaire->id, 'category_id' => (int) $catId, 'rater_type' => $role], ['comment' => $comment]);
            }
        });

        if ($request->boolean('submit')) {
            $answered = QuestionnaireResponse::where('questionnaire_id', $questionnaire->id)->where('rater_type', $role)->count();
            if ($answered < $questionIds->count()) {
                throw ValidationException::withMessages(['ratings' => 'Rate every question before submitting ('.($questionIds->count() - $answered).' left).']);
            }
            $questionnaire->{$submittedField} = now();
            $questionnaire->status = $questionnaire->self_submitted_at && $questionnaire->supervisor_submitted_at
                ? 'completed' : ($role === 'self' ? 'self_submitted' : 'supervisor_submitted');
            $questionnaire->save();

            $appraisal = Appraisal::withoutGlobalScopes()->where('cycle_id', $questionnaire->cycle_id)->where('employee_id', $questionnaire->employee_id)->first();
            if ($role === 'self') {
                if ($appraisal && in_array($appraisal->status, ['pending', 'self_review'], true)) {
                    $appraisal->update(['status' => 'manager_review', 'questionnaire_id' => $questionnaire->id]);
                }
                $questionnaire->supervisor?->user?->notify(new PerformanceNotification('Self-assessment submitted',
                    "{$questionnaire->employee->full_name} completed their self-assessment. Please add your ratings.", "/performance/reviews/{$questionnaire->id}", 'clipboard-check'));
            } else {
                $questionnaire->employee?->user?->notify(new PerformanceNotification('Supervisor review completed',
                    "Your supervisor has rated \"{$questionnaire->title}\". View the comparison.", "/performance/reviews/{$questionnaire->id}", 'clipboard-check'));
            }
            activity()->performedOn($questionnaire)->causedBy($request->user())->withProperties(['rater' => $role])->log('Competency review submitted');
        }

        return response()->json(['message' => $request->boolean('submit') ? 'Review submitted.' : 'Draft saved.']);
    }

    public function destroy(Request $request, Questionnaire $questionnaire): JsonResponse
    {
        abort_unless(PerformanceAccess::canManage($request->user(), $questionnaire->employee_id), 403);
        abort_if($questionnaire->self_submitted_at || $questionnaire->supervisor_submitted_at, 422, 'A submitted review cannot be deleted.');
        $questionnaire->delete();

        return response()->json(['message' => 'Review deleted.']);
    }

    private function role(Request $request, Questionnaire $q): ?string
    {
        $me = $request->user()->employee;
        if ($me && $q->employee_id === $me->id) {
            return 'self';
        }
        if (($me && $q->supervisor_id === $me->id) || PerformanceAccess::canManage($request->user(), $q->employee_id)) {
            return 'supervisor';
        }

        return null;
    }

    private function row(Questionnaire $q, Request $request): array
    {
        $role = $this->role($request, $q);
        $raters = $q->supervisor_id ? 2 : 1;

        return [
            'id' => $q->id, 'title' => $q->title, 'cycle' => $q->cycle?->only(['id', 'name']),
            'employee' => $this->person($q->employee), 'subject_name' => $q->employee?->full_name,
            'supervisor' => $q->supervisor ? ['id' => $q->supervisor->id, 'name' => $q->supervisor->full_name] : null,
            'due_date' => $q->due_date?->toDateString(), 'status' => $q->status,
            'questions_count' => $q->questions_count ?? null,
            'reviewers_count' => $raters,
            'responses' => ($q->self_submitted_at ? 1 : 0) + ($q->supervisor_submitted_at ? 1 : 0),
            'self_submitted_at' => $q->self_submitted_at, 'supervisor_submitted_at' => $q->supervisor_submitted_at,
            'my_role' => $role,
            'can_rate' => ($role === 'self' && ! $q->self_submitted_at) || ($role === 'supervisor' && ! $q->supervisor_submitted_at),
            'overdue' => $q->status !== 'completed' && $q->due_date?->isPast(),
        ];
    }
}
