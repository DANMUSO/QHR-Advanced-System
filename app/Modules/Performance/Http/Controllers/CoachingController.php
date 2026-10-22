<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Compliance\Services\ComplianceService;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\CoachingSession;
use App\Modules\Performance\Models\DiscussionPoint;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Pip;
use App\Modules\Performance\Models\PipCheckin;
use App\Modules\Performance\Notifications\PerformanceNotification;
use App\Modules\Performance\Services\PerformanceAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Performance Boost Program: discussion points, GROW coaching sessions and PIPs (employee, supervisor and HR). */
class CoachingController extends Controller
{
    use PresentsPeople;

    private const PEOPLE = ['employee.position:id,title', 'employee.department:id,name', 'supervisor:id,first_name,last_name'];

    /** Unified list of coaching sessions and PIPs. */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $rows = collect();
        if (! $type || in_array($type, ['coaching', 'mentoring'], true)) {
            $rows = $rows->merge($this->scoped(CoachingSession::query(), $request)->with(self::PEOPLE)->latest('session_date')->limit(300)->get()
                ->map(fn (CoachingSession $s) => $this->sessionRow($s)));
        }
        if (! $type || $type === 'pip') {
            $rows = $rows->merge($this->scoped(Pip::query(), $request)->with(self::PEOPLE)->withCount('checkins')->latest('start_date')->limit(300)->get()
                ->map(fn (Pip $p) => $this->pipRow($p)));
        }
        $rows = $rows->when($request->filled('status'), fn ($c) => $c->where('status', $request->query('status')))
            ->sortByDesc('start_date')->values();

        return response()->json(['data' => $rows, 'summary' => [
            'sessions' => $rows->where('type', 'coaching')->count(),
            'active_pips' => $rows->where('type', 'pip')->whereIn('status', ['active', 'extended'])->count(),
            'open_points' => $this->scoped(DiscussionPoint::query(), $request)->where('status', 'open')->count(),
            'due_checkins' => $rows->where('type', 'pip')->filter(fn ($r) => $r['next_checkin_date'] && $r['next_checkin_date'] <= now()->toDateString())->count(),
        ]]);
    }

    /** Quick create from the management screen: type coaching | mentoring (GROW session) or pip. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required'],
            'type' => ['required', Rule::in(['coaching', 'mentoring', 'pip'])],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date', Rule::requiredIf($request->input('type') === 'pip')],
            'objectives' => ['required', 'string', 'max:5000'],
            'topic' => ['nullable', 'string', 'max:200'],
        ], [], ['objectives' => 'objectives / concern']);
        $employee = $this->findEmployee($data['employee_id']);
        $this->authorizeManage($request, $employee->id);

        if ($data['type'] === 'pip') {
            $pip = $this->createPip($request, $employee, [
                'concern' => $data['objectives'], 'targets' => $data['objectives'], 'action_plan' => 'To be agreed in the first check-in.',
                'start_date' => $data['start_date'], 'end_date' => $data['end_date'], 'review_frequency' => 'biweekly',
            ]);

            return response()->json(['data' => $this->pipRow($pip->load(self::PEOPLE)), 'message' => 'PIP created.'], 201);
        }
        $session = $this->createSession($request, $employee, [
            'session_date' => $data['start_date'], 'next_meeting_date' => $data['end_date'] ?? null,
            'topic' => $data['topic'] ?? ($data['type'] === 'mentoring' ? 'Mentoring session' : 'Coaching session'),
            'goal' => $data['objectives'],
        ]);

        return response()->json(['data' => $this->sessionRow($session->load(self::PEOPLE)), 'message' => 'Coaching session recorded.'], 201);
    }

    /** Self-service: my discussion points, coaching sessions and PIPs. */
    public function mine(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $with = array_merge(self::PEOPLE, ['goal.kpi:id,name']);

        return response()->json([
            'points' => DiscussionPoint::where('employee_id', $me->id)->with($with)->latest('id')->limit(100)->get()->map(fn ($p) => $this->pointRow($p, $request)),
            'sessions' => CoachingSession::where('employee_id', $me->id)->with($with)->latest('session_date')->limit(100)->get()->map(fn ($s) => $this->sessionDetail($s, $request)),
            'pips' => Pip::where('employee_id', $me->id)->with(array_merge(self::PEOPLE, ['checkins.recorder:id,name']))->latest('start_date')->get()->map(fn ($p) => $this->pipDetail($p, $request)),
        ]);
    }

    // ---------------------------------------------------------------- discussion points

    public function points(Request $request): JsonResponse
    {
        $rows = $this->scoped(DiscussionPoint::query(), $request)->with(array_merge(self::PEOPLE, ['goal.kpi:id,name']))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->orderByRaw("CASE status WHEN 'open' THEN 1 WHEN 'extended' THEN 2 WHEN 'escalated' THEN 3 WHEN 'completed' THEN 4 ELSE 5 END")->orderBy('deadline')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->pointRow($p, $request))]);
    }

    public function storePoint(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required_without:goal_id', 'nullable', 'integer', 'exists:employees,id'],
            'goal_id' => ['nullable', 'integer', 'exists:perf_goals,id'],
            'activity' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'deadline' => ['nullable', 'date'],
        ]);
        $goal = ! empty($data['goal_id']) ? Goal::findOrFail($data['goal_id']) : null;
        $employee = Employee::findOrFail($goal?->employee_id ?? $data['employee_id']);
        $this->authorizeManage($request, $employee->id);
        $point = new DiscussionPoint([
            'employee_id' => $employee->id, 'supervisor_id' => $request->user()->employee?->id ?? $employee->primary_supervisor_id,
            'goal_id' => $goal?->id, 'activity' => $data['activity'], 'comment' => $data['comment'] ?? null,
            'deadline' => $data['deadline'] ?? null, 'status' => 'open', 'created_by_user_id' => $request->user()->id,
        ]);
        $point->company_id = $employee->company_id;
        $point->save();
        $employee->user?->notify(new PerformanceNotification('New coaching discussion point', "{$this->actorName($request->user())} added: {$point->activity}", '/performance?tab=coaching', 'message-circle', false));

        return response()->json(['data' => $this->pointRow($point->load(self::PEOPLE), $request), 'message' => 'Discussion point added.'], 201);
    }

    public function updatePoint(Request $request, DiscussionPoint $point): JsonResponse
    {
        $this->authorizeManage($request, $point->employee_id);
        $point->update($request->validate([
            'activity' => ['sometimes', 'string', 'max:255'], 'comment' => ['nullable', 'string', 'max:3000'],
            'deadline' => ['nullable', 'date'], 'status' => ['sometimes', Rule::in(DiscussionPoint::STATUSES)],
        ]));

        return response()->json(['data' => $this->pointRow($point->load(self::PEOPLE), $request), 'message' => 'Discussion point updated.']);
    }

    public function destroyPoint(Request $request, DiscussionPoint $point): JsonResponse
    {
        $this->authorizeManage($request, $point->employee_id);
        $point->delete();

        return response()->json(['message' => 'Discussion point deleted.']);
    }

    // ---------------------------------------------------------------- GROW sessions

    public function sessions(Request $request): JsonResponse
    {
        $rows = $this->scoped(CoachingSession::query(), $request)->with(self::PEOPLE)->latest('session_date')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($s) => $this->sessionDetail($s, $request))]);
    }

    public function storeSession(Request $request): JsonResponse
    {
        $data = $this->validatedSession($request, true);
        $employee = Employee::findOrFail($data['employee_id']);
        $this->authorizeManage($request, $employee->id);
        $session = $this->createSession($request, $employee, $data);

        return response()->json(['data' => $this->sessionDetail($session->load(self::PEOPLE), $request), 'message' => 'Coaching session recorded.'], 201);
    }

    public function showSession(Request $request, CoachingSession $session): JsonResponse
    {
        $this->authorizeView($request, $session->employee_id, $session->supervisor_id);

        return response()->json(['data' => $this->sessionDetail($session->load(array_merge(self::PEOPLE, ['goal.kpi:id,name', 'pip:id,status'])), $request)]);
    }

    public function updateSession(Request $request, CoachingSession $session): JsonResponse
    {
        $user = $request->user();
        $isEmployee = $user->employee?->id === $session->employee_id;
        if ($isEmployee && ! PerformanceAccess::isAdmin($user)) {
            $session->update($request->validate(['staff_comment' => ['nullable', 'string', 'max:3000']]));
        } else {
            $this->authorizeManage($request, $session->employee_id);
            $data = $this->validatedSession($request, false);
            if (! PerformanceAccess::isAdmin($user)) {
                unset($data['hr_comment']);
            }
            unset($data['employee_id']);
            $session->update($data);
        }

        return response()->json(['data' => $this->sessionDetail($session->fresh(self::PEOPLE), $request), 'message' => 'Session updated.']);
    }

    /** GROW outcome "escalate": open a PIP linked to the session. */
    public function escalate(Request $request, CoachingSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session->employee_id);
        abort_if($session->pip_id, 422, 'This session has already been escalated to a PIP.');
        $data = $this->validatedPip($request);
        $pip = $this->createPip($request, $session->employee, $data);
        $session->update(['outcome' => 'escalated', 'pip_id' => $pip->id]);
        DiscussionPoint::where('employee_id', $session->employee_id)->where('status', 'open')
            ->when($session->goal_id, fn ($q) => $q->where('goal_id', $session->goal_id))->update(['status' => 'escalated']);

        return response()->json(['data' => $this->pipDetail($pip->fresh(array_merge(self::PEOPLE, ['checkins.recorder:id,name'])), $request), 'message' => 'Escalated to a Performance Improvement Plan.'], 201);
    }

    public function destroySession(Request $request, CoachingSession $session): JsonResponse
    {
        $this->authorizeManage($request, $session->employee_id);
        $session->delete();

        return response()->json(['message' => 'Session deleted.']);
    }

    // ---------------------------------------------------------------- PIPs

    public function pips(Request $request): JsonResponse
    {
        $rows = $this->scoped(Pip::query(), $request)->with(self::PEOPLE)->withCount('checkins')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->query('status')))
            ->latest('start_date')->limit(300)->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->pipRow($p))]);
    }

    public function storePip(Request $request): JsonResponse
    {
        $data = $this->validatedPip($request, true);
        $employee = Employee::findOrFail($data['employee_id']);
        $this->authorizeManage($request, $employee->id);
        unset($data['employee_id']);
        $pip = $this->createPip($request, $employee, $data);

        return response()->json(['data' => $this->pipDetail($pip->load(self::PEOPLE), $request), 'message' => 'PIP created.'], 201);
    }

    public function showPip(Request $request, Pip $pip): JsonResponse
    {
        $this->authorizeView($request, $pip->employee_id, $pip->supervisor_id);

        return response()->json(['data' => $this->pipDetail($pip->load(array_merge(self::PEOPLE, ['checkins.recorder:id,name'])), $request)]);
    }

    public function updatePip(Request $request, Pip $pip): JsonResponse
    {
        $this->authorizeManage($request, $pip->employee_id);
        abort_unless($pip->isOpen(), 422, 'This PIP is closed.');
        $data = $this->validatedPip($request);
        if (isset($data['end_date']) && $pip->end_date && $data['end_date'] > $pip->end_date->toDateString()) {
            $data['status'] = 'extended';
        }
        $pip->update($data);

        return response()->json(['data' => $this->pipDetail($pip->fresh(array_merge(self::PEOPLE, ['checkins.recorder:id,name'])), $request), 'message' => 'PIP updated.']);
    }

    public function checkin(Request $request, Pip $pip): JsonResponse
    {
        $this->authorizeManage($request, $pip->employee_id);
        abort_unless($pip->isOpen(), 422, 'This PIP is closed.');
        $data = $request->validate([
            'meeting_date' => ['required', 'date', 'before_or_equal:today'],
            'progress_rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:3000'],
            'recommendation' => ['required', Rule::in(PipCheckin::RECOMMENDATIONS)],
            'new_end_date' => ['nullable', 'date', 'after:today', Rule::requiredIf($request->input('recommendation') === 'extend')],
        ]);
        $pip->checkins()->create([
            'meeting_date' => $data['meeting_date'], 'progress_rating' => $data['progress_rating'], 'comment' => $data['comment'] ?? null,
            'recommendation' => $data['recommendation'], 'recorded_by_user_id' => $request->user()->id,
        ]);
        if ($data['recommendation'] === 'extend') {
            $pip->update(['end_date' => $data['new_end_date'], 'status' => 'extended']);
        }
        $pip->employee?->user?->notify(new PerformanceNotification('PIP check-in recorded',
            "Progress rated {$data['progress_rating']}/5. Recommendation: {$data['recommendation']}.", '/performance?tab=coaching', 'clipboard-list'));

        return response()->json(['data' => $this->pipDetail($pip->fresh(array_merge(self::PEOPLE, ['checkins.recorder:id,name'])), $request), 'message' => 'Check-in recorded.'], 201);
    }

    public function closePip(Request $request, Pip $pip): JsonResponse
    {
        $this->authorizeManage($request, $pip->employee_id);
        abort_unless($pip->isOpen(), 422, 'This PIP is already closed.');
        $data = $request->validate(['outcome' => ['required', Rule::in(Pip::OUTCOMES)], 'comment' => ['required', 'string', 'max:3000']]);
        $pip->update([
            'status' => $data['outcome'] === 'escalated' ? 'escalated' : 'completed', 'outcome' => $data['outcome'],
            'outcome_comment' => $data['comment'], 'closed_at' => now(),
        ]);
        activity()->performedOn($pip)->causedBy($request->user())->withProperties($data)->log('PIP closed');
        $pip->employee?->user?->notify(new PerformanceNotification('PIP closed', "Your performance improvement plan was closed ({$data['outcome']}).", '/performance?tab=coaching', 'clipboard-list'));
        $case = $data['outcome'] === 'escalated' ? app(ComplianceService::class)->openCaseFromPip($pip, $request->user()) : null;

        return response()->json([
            'data' => $this->pipDetail($pip->fresh(array_merge(self::PEOPLE, ['checkins.recorder:id,name'])), $request),
            'disciplinary_case' => $case?->only(['id', 'case_number', 'status']),
            'message' => $case ? "PIP closed and disciplinary case {$case->case_number} opened." : 'PIP closed.',
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** Employee: own records; supervisor: their tree; HR: everything. ?scope=mine narrows to the user. */
    private function scoped(Builder $q, Request $request): Builder
    {
        $user = $request->user();
        $me = $user->employee;
        if ($request->query('scope') === 'mine') {
            return $q->where('employee_id', $me?->id);
        }
        $q->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')));
        if (PerformanceAccess::isAdmin($user)) {
            return $q;
        }
        $tree = $me ? PerformanceAccess::subordinateIds($me->id) : collect();

        return $q->where(fn ($w) => $w->where('employee_id', $me?->id)->orWhere('supervisor_id', $me?->id)->orWhereIn('employee_id', $tree));
    }

    private function createSession(Request $request, Employee $employee, array $data): CoachingSession
    {
        $session = new CoachingSession(array_merge($data, [
            'employee_id' => $employee->id,
            'supervisor_id' => $request->user()->employee?->id !== $employee->id ? ($request->user()->employee?->id ?? $employee->primary_supervisor_id) : $employee->primary_supervisor_id,
            'created_by_user_id' => $request->user()->id,
        ]));
        $session->company_id = $employee->company_id;
        $session->save();
        $employee->user?->notify(new PerformanceNotification('Coaching session recorded', "A coaching session \"{$session->topic}\" was recorded. Add your comments.", '/performance?tab=coaching', 'message-circle', false));

        return $session;
    }

    private function createPip(Request $request, Employee $employee, array $data): Pip
    {
        abort_if(Pip::where('employee_id', $employee->id)->whereIn('status', ['active', 'extended'])->exists(), 422, "{$employee->full_name} already has an open PIP.");
        $pip = new Pip(array_merge($data, [
            'employee_id' => $employee->id, 'supervisor_id' => $request->user()->employee?->id ?? $employee->primary_supervisor_id,
            'status' => 'active', 'created_by_user_id' => $request->user()->id,
        ]));
        $pip->company_id = $employee->company_id;
        $pip->save();
        activity()->performedOn($pip)->causedBy($request->user())->log('PIP started');
        $employee->user?->notify(new PerformanceNotification('Performance Improvement Plan started',
            "A PIP running {$pip->start_date->format('d M Y')} – {$pip->end_date->format('d M Y')} has been set up with you.", '/performance?tab=coaching', 'clipboard-list'));

        return $pip;
    }

    private function validatedSession(Request $request, bool $creating): array
    {
        return $request->validate([
            'employee_id' => [$creating ? 'required' : 'sometimes', 'integer', 'exists:employees,id'],
            'goal_id' => ['nullable', 'integer', 'exists:perf_goals,id'],
            'session_date' => [$creating ? 'required' : 'sometimes', 'date'],
            'topic' => [$creating ? 'required' : 'sometimes', 'string', 'max:200'],
            'goal' => ['nullable', 'string', 'max:3000'], 'reality' => ['nullable', 'string', 'max:3000'],
            'options' => ['nullable', 'string', 'max:3000'], 'way_forward' => ['nullable', 'string', 'max:3000'],
            'staff_comment' => ['nullable', 'string', 'max:3000'], 'supervisor_comment' => ['nullable', 'string', 'max:3000'],
            'hr_comment' => ['nullable', 'string', 'max:3000'],
            'next_meeting_date' => ['nullable', 'date'],
            'outcome' => ['nullable', Rule::in(['completed', 'extended'])],
        ]);
    }

    private function validatedPip(Request $request, bool $creating = false): array
    {
        $req = $creating || $request->routeIs('*.escalate') || str_ends_with($request->path(), 'escalate') ? 'required' : 'sometimes';

        return $request->validate([
            'employee_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:employees,id'],
            'concern' => [$req, 'string', 'max:5000'], 'targets' => [$req, 'string', 'max:5000'],
            'action_plan' => [$req, 'string', 'max:5000'], 'resources' => ['nullable', 'string', 'max:3000'],
            'start_date' => [$req, 'date'], 'end_date' => [$req, 'date', 'after:start_date'],
            'review_frequency' => [$req, Rule::in(array_keys(Pip::FREQUENCIES))],
        ]);
    }

    private function authorizeManage(Request $request, int $employeeId): void
    {
        abort_unless(PerformanceAccess::canManage($request->user(), $employeeId), 403, 'Only the employee\'s supervisor or HR can do this.');
    }

    private function authorizeView(Request $request, int $employeeId, ?int $supervisorId): void
    {
        abort_unless($request->user()->employee?->id === $supervisorId || PerformanceAccess::canView($request->user(), $employeeId), 403);
    }

    private function pointRow(DiscussionPoint $p, Request $request): array
    {
        return $p->toArray() + [
            'employee' => $this->person($p->employee), 'supervisor_name' => $p->supervisor?->full_name,
            'kpi_name' => $p->goal?->kpi?->name, 'can_manage' => PerformanceAccess::canManage($request->user(), $p->employee_id),
            'overdue' => $p->status === 'open' && $p->deadline?->isPast(),
        ];
    }

    private function sessionRow(CoachingSession $s): array
    {
        return [
            'id' => "coaching-{$s->id}", 'record_id' => $s->id, 'type' => 'coaching', 'title' => $s->topic,
            'employee' => $this->person($s->employee), 'employee_name' => $s->employee?->full_name,
            'start_date' => $s->session_date?->toDateString(), 'end_date' => $s->next_meeting_date?->toDateString(),
            'coach_name' => $s->supervisor?->full_name,
            'status' => $s->outcome === 'escalated' ? 'escalated' : ($s->outcome === 'completed' ? 'completed' : 'active'),
            'outcome' => $s->outcome, 'pip_id' => $s->pip_id, 'next_checkin_date' => null,
        ];
    }

    private function pipRow(Pip $p): array
    {
        return [
            'id' => "pip-{$p->id}", 'record_id' => $p->id, 'type' => 'pip', 'title' => str($p->concern)->limit(80)->toString(),
            'employee' => $this->person($p->employee), 'employee_name' => $p->employee?->full_name,
            'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(),
            'coach_name' => $p->supervisor?->full_name, 'status' => $p->status, 'outcome' => $p->outcome,
            'review_frequency' => $p->review_frequency, 'checkins_count' => $p->checkins_count ?? null,
            'next_checkin_date' => $p->next_checkin_date,
        ];
    }

    private function sessionDetail(CoachingSession $s, Request $request): array
    {
        $user = $request->user();

        return $s->toArray() + [
            'employee' => $this->person($s->employee), 'supervisor_name' => $s->supervisor?->full_name,
            'kpi_name' => $s->goal?->kpi?->name,
            'can_manage' => PerformanceAccess::canManage($user, $s->employee_id),
            'can_comment_staff' => $user->employee?->id === $s->employee_id,
            'can_comment_hr' => PerformanceAccess::isAdmin($user),
        ];
    }

    private function pipDetail(Pip $p, Request $request): array
    {
        return $p->toArray() + [
            'employee' => $this->person($p->employee), 'supervisor_name' => $p->supervisor?->full_name,
            'checkins' => $p->relationLoaded('checkins') ? $p->checkins->map(fn ($c) => $c->toArray() + ['recorded_by' => $c->recorder?->name]) : [],
            'can_manage' => PerformanceAccess::canManage($request->user(), $p->employee_id),
            'disciplinary_case' => $p->status === 'escalated' ? \App\Modules\Compliance\Models\DisciplinaryCase::where('pip_id', $p->id)->first(['id', 'case_number', 'status']) : null,
            'progress_trend' => $p->relationLoaded('checkins') ? $p->checkins->sortBy('meeting_date')->map(fn ($c) => ['date' => $c->meeting_date->toDateString(), 'rating' => $c->progress_rating])->values() : [],
        ];
    }
}
