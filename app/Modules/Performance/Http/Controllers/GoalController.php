<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Performance\Dashboard\KpiApprovalProvider;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Services\AppraisalService;
use App\Modules\Performance\Services\GoalWorkflow;
use App\Modules\Performance\Services\PerformanceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GoalController extends Controller
{
    use PresentsPeople;

    private const WITH = ['kpi.unit', 'kpi.frequency', 'kpi.objective.focusArea', 'kpi.plan:id,name', 'kpi.questions:id,question',
        'cycle:id,name,status,max_score_pct', 'employee.position:id,title', 'employee.department:id,name', 'approver:id,first_name,last_name'];

    public function __construct(private GoalWorkflow $workflow) {}

    public function mine(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $base = fn () => $this->filtered(Goal::where('employee_id', $me->id), $request, withStatus: false);

        $goals = $this->filtered(Goal::where('employee_id', $me->id), $request)
            ->with(self::WITH)->withCount('children')
            ->orderByRaw("CASE status WHEN 'cascaded' THEN 1 WHEN 'under_review' THEN 2 WHEN 'in_progress' THEN 3 WHEN 'submitted' THEN 4 WHEN 'approved' THEN 5 ELSE 6 END")->orderBy('due_date')
            ->paginate(min($request->integer('per_page', 50), 100));

        $counts = $base()->selectRaw('status, COUNT(*) as c')->groupBy('status')->pluck('c', 'status');
        $all = $base()->get(['target', 'achieved', 'status', 'achievement_pct']);
        $cycle = $request->filled('cycle_id') ? Cycle::find($request->integer('cycle_id')) : Cycle::current();

        return response()->json(array_merge($goals->through(fn ($g) => $this->present($g, $request))->toArray(), [
            'summary' => [
                'total' => $all->count(),
                'by_status' => collect(Goal::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($counts[$s] ?? 0)]),
                'completion_pct' => $all->count() ? (int) round($all->where('status', 'approved')->count() / $all->count() * 100) : 0,
                'avg_achievement' => round((float) $all->where('status', 'approved')->avg('achievement_pct'), 1),
                'kpi_score' => $cycle ? (app(AppraisalService::class)->kpiScores($cycle, [$me->id])[$me->id] ?? null) : null,
                'due_soon' => $base()->open()->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])->count(),
                'overdue' => $base()->open()->where('due_date', '<', now()->toDateString())->count(),
            ],
        ]));
    }

    public function team(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Goal::query();
        $teamIds = PerformanceAccess::teamIds($user);
        $meId = $user->employee?->id;

        if ($request->boolean('awaiting')) {
            $query->whereIn('status', ['submitted', 'under_review'])
                ->when(! PerformanceAccess::isAdmin($user) || $request->query('scope') !== 'all', fn ($q) => $q->where(function ($w) use ($meId, $user) {
                    $w->where('approver_id', $meId);
                    if (PerformanceAccess::isAdmin($user)) {
                        // Admin fallback: approver without a usable login and nobody reachable above them.
                        $w->orWhere(fn ($x) => \App\Modules\Employees\Support\ApproverResolver::whereApproverUnreachable($x, 'approver_id'));
                    }
                }));
        } elseif ($teamIds !== null || $request->query('scope') !== 'all') {
            $ids = $teamIds ?? ($meId ? PerformanceAccess::subordinateIds($meId) : collect());
            $query->where(fn ($q) => $q->whereIn('employee_id', $ids)->orWhere('approver_id', $meId));
        }

        $goals = $this->filtered($query, $request)
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->whereHas('employee', fn ($e) => $e->search($request->query('search')))
                ->orWhereHas('kpi', fn ($k) => $k->where('name', 'like', '%'.$request->query('search').'%'))))
            ->with(self::WITH)->withCount('children')
            ->orderByRaw("CASE status WHEN 'submitted' THEN 1 WHEN 'under_review' THEN 2 WHEN 'cascaded' THEN 3 WHEN 'in_progress' THEN 4 WHEN 'approved' THEN 5 ELSE 6 END")->orderBy('due_date')
            ->paginate(min($request->integer('per_page', 50), 200));

        return response()->json($goals->through(fn ($g) => $this->present($g, $request)));
    }

    /** The user's KPI decision queue (results to approve, review requests): the same source as the dashboard inbox. */
    public function approvals(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PerformanceAccess::isAdmin($user) && $request->query('scope') === 'all'
            ? Goal::whereIn('status', ['submitted', 'under_review'])
            : Goal::whereKey(KpiApprovalProvider::goals($user)->pluck('id'));
        $goals = $query->with(self::WITH)->withCount('children')->orderBy('updated_at')->limit(300)->get();

        return response()->json(['data' => $goals->map(fn ($g) => $this->present($g, $request))->values()]);
    }

    /** Per-member progress for the supervisor's direct reports (or a department for HR). */
    public function teamOverview(Request $request): JsonResponse
    {
        $user = $request->user();
        $cycle = $request->filled('cycle_id') ? Cycle::findOrFail($request->integer('cycle_id')) : Cycle::current();
        $ids = $user->employee ? PerformanceAccess::directReportIds($user->employee->id) : collect();
        if (PerformanceAccess::isAdmin($user) && $request->filled('department_id')) {
            $ids = Employee::active()->where('department_id', $request->integer('department_id'))->pluck('id');
        }
        $members = Employee::whereIn('id', $ids)->with('position:id,title', 'department:id,name')->orderBy('first_name')->get();
        $goals = $cycle ? Goal::where('cycle_id', $cycle->id)->whereIn('employee_id', $ids)->get(['employee_id', 'status', 'due_date']) : collect();
        $scores = $cycle ? app(AppraisalService::class)->kpiScores($cycle, $ids) : [];

        $rows = $members->map(function ($m) use ($goals, $scores) {
            $g = $goals->where('employee_id', $m->id);
            $counts = collect(Goal::STATUSES)->mapWithKeys(fn ($s) => [$s => $g->where('status', $s)->count()]);

            return $this->person($m) + [
                'goals_count' => $g->count(), 'by_status' => $counts,
                'completion_pct' => $g->count() ? (int) round($counts['approved'] / $g->count() * 100) : 0,
                'overdue' => $g->filter(fn ($x) => in_array($x->status, Goal::OPEN, true) && $x->due_date->isPast())->count(),
                'kpi_score' => $scores[$m->id] ?? null,
            ];
        });
        $total = $goals->count();

        return response()->json([
            'cycle' => $cycle?->only(['id', 'name', 'status']),
            'members' => $rows->values(),
            'summary' => [
                'members' => $members->count(), 'goals' => $total,
                'completion_pct' => $total ? (int) round($goals->where('status', 'approved')->count() / $total * 100) : 0,
                'awaiting_approval' => Goal::where('approver_id', $user->employee?->id)->where('status', 'submitted')->count(),
                'review_requests' => Goal::where('approver_id', $user->employee?->id)->where('status', 'under_review')->count(),
                'not_accepted' => $goals->where('status', 'cascaded')->count(),
            ],
        ]);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeView($request, $goal);
        $goal->load(array_merge(self::WITH, ['events.user:id,name', 'events.employee:id,first_name,last_name,photo_path', 'ratings',
            'parent.employee:id,first_name,last_name', 'children.employee:id,first_name,last_name,photo_path']));

        return response()->json(['data' => $this->present($goal, $request, detail: true)]);
    }

    public function accept(Request $request, Goal $goal): JsonResponse
    {
        return $this->done($request, $this->workflow->accept($goal, $request->user()), 'Goal accepted.');
    }

    public function convertToSelf(Request $request, Goal $goal): JsonResponse
    {
        return $this->done($request, $this->workflow->convertToSelf($goal, $request->user()), 'Goal converted to a self-assessment.');
    }

    public function requestReview(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->done($request, $this->workflow->requestReview($goal, $request->user(), $data['reason']), 'Review requested from your supervisor.');
    }

    public function cascade(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate([
            'split' => ['nullable', Rule::in(['distributed', 'equal'])],
            'assignees' => ['required', 'array', 'min:1'],
            'assignees.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'assignees.*.target' => ['nullable', 'numeric', 'min:0'],
        ]);
        $goals = $this->workflow->cascadeGoal($goal, $request->user(), $data['split'] ?? null, $data['assignees']);

        return $this->done($request, $goal->fresh(), 'Cascaded to '.count($goals).' team member'.(count($goals) === 1 ? '' : 's').'.');
    }

    public function submit(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate([
            'achieved' => ['nullable', 'numeric', 'min:0'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt'],
            'ratings' => ['nullable', 'array'],
            'ratings.*' => ['integer', 'min:1', 'max:10'],
        ]);
        $goal = $this->workflow->submit($goal, $request->user(), isset($data['achieved']) ? (float) $data['achieved'] : null,
            $data['comment'] ?? null, $request->file('evidence'), $data['ratings'] ?? null);

        return $this->done($request, $goal, $goal->status === 'approved' ? 'Result recorded.' : 'Result submitted for approval.');
    }

    public function comment(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeView($request, $goal);
        $data = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $this->workflow->comment($goal, $request->user(), $data['comment']);

        return $this->done($request, $goal, 'Comment added.');
    }

    public function approve(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $goal = $this->workflow->approve($goal, $request->user(), $data['comment'] ?? null);

        return $this->done($request, $goal, $goal->status === 'approved' ? 'Result approved.' : 'Approved — passed to the next approver.');
    }

    public function reject(Request $request, Goal $goal): JsonResponse
    {
        $reason = $request->input('reason', $request->input('comment'));
        $request->merge(['reason' => $reason])->validate(['reason' => ['required', 'string', 'max:2000']]);

        return $this->done($request, $this->workflow->reject($goal, $request->user(), $reason), 'Submission rejected and returned to the employee.');
    }

    /** Combined approve/reject used by the team screen: { approve: bool, comment }. */
    public function decide(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'comment' => ['nullable', 'string', 'max:2000']]);
        if (! $data['approve'] && blank($data['comment'] ?? null)) {
            return response()->json(['message' => 'Give a reason for rejecting.', 'errors' => ['comment' => ['Give a reason for rejecting.']]], 422);
        }

        return $data['approve']
            ? $this->done($request, $this->workflow->approve($goal, $request->user(), $data['comment'] ?? null), 'Result approved.')
            : $this->done($request, $this->workflow->reject($goal, $request->user(), $data['comment']), 'Submission rejected.');
    }

    public function extend(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate(['due_date' => ['required', 'date', 'after:today'], 'comment' => ['nullable', 'string', 'max:2000']]);

        return $this->done($request, $this->workflow->extend($goal, $request->user(), $data['due_date'], $data['comment'] ?? null), 'Deadline extended.');
    }

    public function amend(Request $request, Goal $goal): JsonResponse
    {
        $data = $request->validate([
            'target' => ['nullable', 'numeric', 'gt:0'], 'due_date' => ['nullable', 'date'], 'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->done($request, $this->workflow->amend($goal, $request->user(), isset($data['target']) ? (float) $data['target'] : null,
            $data['due_date'] ?? null, $data['comment'] ?? null), 'Goal amended and sent back to the employee.');
    }

    public function rejectReview(Request $request, Goal $goal): JsonResponse
    {
        $comment = $request->input('comment', $request->input('reason'));
        $request->merge(['comment' => $comment])->validate(['comment' => ['required', 'string', 'max:2000']]);

        return $this->done($request, $this->workflow->rejectReview($goal, $request->user(), $comment), 'Review request declined.');
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        $this->workflow->withdraw($goal, $request->user());

        return response()->json(['message' => 'Goal withdrawn.']);
    }

    public function evidence(Request $request, Goal $goal): StreamedResponse
    {
        $this->authorizeView($request, $goal);
        abort_unless($goal->evidence_path && Storage::disk('local')->exists($goal->evidence_path), 404);

        return Storage::disk('local')->download($goal->evidence_path, $goal->evidence_name ?? basename($goal->evidence_path));
    }

    // ---------------------------------------------------------------- helpers

    private function filtered(Builder $q, Request $request, bool $withStatus = true): Builder
    {
        return $q->when($withStatus && $request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->query('status'))))
            ->when($request->filled('cycle_id'), fn ($q) => $q->where('cycle_id', $request->integer('cycle_id')))
            ->period($request->integer('year') ?: null, $request->integer('quarter') ?: null, $request->integer('month') ?: null);
    }

    private function done(Request $request, Goal $goal, string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => $this->present($goal->fresh(self::WITH)->loadCount('children'), $request)]);
    }

    private function authorizeView(Request $request, Goal $goal): void
    {
        $user = $request->user();
        abort_unless($goal->approver_id === $user->employee?->id || PerformanceAccess::canView($user, $goal->employee_id), 403);
    }

    public function present(Goal $g, Request $request, bool $detail = false): array
    {
        $user = $request->user();
        $kpi = $g->kpi;
        $isOwner = $user->employee && $g->employee_id === $user->employee->id;
        $isApprover = $this->workflow->isApprover($g, $user);
        $hasChildren = ($g->children_count ?? 0) > 0 || ($g->relationLoaded('children') && $g->children->isNotEmpty());
        $progress = $g->target > 0 && $g->achieved !== null ? min(100, round($g->achieved / $g->target * 100, 1)) : 0;

        $row = [
            'id' => $g->id,
            'kpi' => $kpi ? [
                'id' => $kpi->id, 'name' => $kpi->name, 'description' => $kpi->description, 'type' => $kpi->type, 'kpi_type' => $kpi->kpi_type,
                'unit' => $kpi->unit?->name, 'is_percentage' => $kpi->isPercentage(), 'frequency' => $kpi->frequency?->name,
                'is_recurring' => $kpi->is_recurring, 'questions' => $kpi->isQualitative() ? $kpi->questions->map->only(['id', 'question']) : [],
            ] : null,
            'objective' => $kpi?->objective ? ['id' => $kpi->objective->id, 'name' => $kpi->objective->name, 'weight' => $kpi->objective->weight] : null,
            'focus_area' => $kpi?->objective?->focusArea?->name,
            'plan' => $kpi?->plan?->name,
            'cycle' => $g->cycle?->only(['id', 'name', 'status']),
            'employee' => $this->person($g->employee),
            'approver' => $g->approver ? ['id' => $g->approver->id, 'name' => $g->approver->full_name] : null,
            'mode' => $g->mode, 'split' => $g->split, 'status' => $g->status,
            'target' => $g->target, 'achieved' => $g->achieved, 'progress_pct' => $progress,
            'achievement_pct' => $g->achievement_pct, 'score' => $g->score,
            'employee_comment' => $g->employee_comment, 'supervisor_comment' => $g->supervisor_comment,
            'review_reason' => $g->review_reason, 'rejection_reason' => $g->rejection_reason,
            'due_date' => $g->due_date?->toDateString(),
            'days_left' => $g->due_date ? (int) now()->startOfDay()->diffInDays($g->due_date, false) : null,
            'has_evidence' => $g->has_evidence, 'evidence_name' => $g->evidence_name,
            'parent_goal_id' => $g->parent_goal_id, 'children_count' => $g->children_count ?? 0, 'is_rollup' => $hasChildren,
            'accepted_at' => $g->accepted_at, 'submitted_at' => $g->submitted_at, 'approved_at' => $g->approved_at, 'created_at' => $g->created_at,
            'can' => [
                'accept' => $isOwner && $g->status === 'cascaded',
                'convert' => $isOwner && $g->status === 'cascaded',
                'review' => $isOwner && in_array($g->status, ['cascaded', 'in_progress'], true),
                'cascade' => $isOwner && in_array($g->status, ['cascaded', 'in_progress'], true) && $user->employee?->directReports()->exists(),
                'submit' => $isOwner && $g->status === 'in_progress',
                'approve' => $isApprover && $g->status === 'submitted',
                'reject' => $isApprover && $g->status === 'submitted',
                'extend' => $isApprover && $g->status !== 'approved',
                'amend' => $isApprover && $g->status === 'under_review',
                'reject_review' => $isApprover && $g->status === 'under_review',
                'withdraw' => $isApprover && $g->status === 'cascaded' && ! $hasChildren,
            ],
        ];

        if ($detail) {
            $row['ratings'] = $g->ratings->pluck('rating', 'question_id');
            $row['parent'] = $g->parent ? ['id' => $g->parent->id, 'employee' => $g->parent->employee?->full_name] : null;
            $row['children'] = $g->children->map(fn ($c) => [
                'id' => $c->id, 'employee' => $this->person($c->employee), 'status' => $c->status,
                'target' => $c->target, 'achieved' => $c->achieved, 'achievement_pct' => $c->achievement_pct,
            ]);
            $row['events'] = $g->events->map(fn ($e) => [
                'id' => $e->id, 'action' => $e->action, 'comment' => $e->comment, 'meta' => $e->meta, 'created_at' => $e->created_at,
                'by' => $e->employee?->full_name ?? $e->user?->name ?? 'System', 'photo_url' => $e->employee?->photo_url,
            ]);
        }

        return $row;
    }
}
