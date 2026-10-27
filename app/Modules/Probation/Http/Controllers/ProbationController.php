<?php

namespace App\Modules\Probation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Services\LetterService;
use App\Modules\Employees\Services\LifecycleService;
use App\Modules\Organization\Support\ApprovalChain;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProbationController extends Controller
{
    /** Probations in progress: on probation, or extended (a fresh review cycle runs after an extension). */
    public function index(Request $request): JsonResponse
    {
        $query = Employee::whereIn('probation_status', ['on_probation', 'extended'])
            ->whereIn('status', ['active', 'suspended'])
            ->with(['position:id,title', 'department:id,name', 'branch:id,name', 'primarySupervisor:id,first_name,last_name', 'probationReview'])
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('probation_status'), fn ($q) => $q->where('probation_status', $request->query('probation_status')))
            ->orderBy('probation_end_date');

        return response()->json($query->paginate(min($request->integer('per_page', 25) ?: 25, 100))->through(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->full_name,
            'employee_number' => $e->employee_number,
            'photo_url' => $e->photo_url,
            'position' => $e->position?->title,
            'department' => $e->department?->name,
            'branch' => $e->branch?->name,
            'supervisor' => $e->primarySupervisor?->full_name,
            'date_of_joining' => $e->date_of_joining?->toDateString(),
            'probation_end_date' => $e->probation_end_date?->toDateString(),
            'probation_status' => $e->probation_status,
            'days_remaining' => $e->probation_end_date ? max(0, (int) today()->diffInDays($e->probation_end_date, false)) : null,
            'review' => $e->probationReview ? $this->presentReview($e->probationReview) : null,
        ]));
    }

    /** The employee's current review; opens one (or the next cycle after an extension) when there is none in progress. */
    public function show(Request $request, Employee $employee): JsonResponse
    {
        $review = ProbationReview::where('employee_id', $employee->id)->latest('id')->first();
        $needsCycle = ! $review || ($review->status === 'extended' && $employee->probation_status === 'extended');
        if ($needsCycle && in_array($employee->probation_status, ['on_probation', 'extended'], true)) {
            $review = $this->routing()->open($employee);
        }
        if (! $review) {
            return response()->json(['data' => null, 'message' => 'This employee is not on probation.']);
        }

        return response()->json(['data' => $this->presentReview($review->load(['employee', 'primaryReviewer:id,name', 'secondaryReviewer:id,name', 'hooReviewer:id,name', 'hrReviewer:id,name']))]);
    }

    /** Reviews whose current stage I can act on (my reports, escalations to me, role-based stages). */
    public function myReviews(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->employee || $user->can('probation.manage'), 403);
        $meId = $user->employee?->id;

        return response()->json(['data' => $this->routing()->queueFor($user)->map(fn (ProbationReview $r) => $this->presentReview($r) + [
            'employee_name' => $r->employee?->full_name,
            'employee_number' => $r->employee?->employee_number,
            'photo_url' => $r->employee?->photo_url,
            'routed_to_me' => $meId && (int) $r->primary_approver_id === (int) $meId,
        ])->values()]);
    }

    /**
     * POST /probation/reviews/{id}/review — the current stage's reviewer submits their comment. Stages run in order;
     * the legacy /primary, /secondary and /hoo paths go through here too and must match the current stage.
     */
    public function stageReview(Request $request, ProbationReview $probationReview): JsonResponse
    {
        $routing = $this->routing();
        abort_unless(in_array($probationReview->status, ProbationRouting::OPEN, true), 422, 'All review stages are complete.');
        $expected = ['primary' => 0, 'secondary' => 1, 'hoo' => 2][$request->route('stage') ?? ''] ?? null;
        abort_if($expected !== null && $expected !== (int) $probationReview->stage_index, 422,
            'This review is at the '.strtolower(ApprovalChain::label($routing->currentStep($probationReview) ?? [])).' stage.');
        abort_unless($routing->canDecide($probationReview, $request->user()), 403, 'You are not the reviewer for this stage.');
        $data = $request->validate(['comment' => ['required', 'string', 'max:3000']]);

        $routing->decide($probationReview, $request->user(), $data['comment']);

        return response()->json(['message' => 'Review submitted.', 'data' => $this->presentReview($probationReview->fresh())]);
    }

    /** HR final decision, only once every review stage is done (or was skipped because nobody could act). */
    public function hrDecision(Request $request, ProbationReview $probationReview): JsonResponse
    {
        abort_unless($probationReview->status === 'hr_review', 422, 'The review stages are not complete yet.');
        $employee = $probationReview->employee;
        abort_if($employee && $request->user()->employee?->id === $employee->id, 403, 'You cannot decide your own probation.');
        abort_unless($employee && in_array($employee->status, ['active', 'suspended'], true), 422, 'This employee is no longer active.');
        // An extension must move the end date later than it is now (and past today); an earlier date is not an extension.
        $currentEnd = collect([$employee->probation_end_date, $probationReview->probation_end_date, today()])->filter()->max();
        // Employment Act s.42: 6 months plus one extension of up to 6 — never beyond 12 months from joining.
        $maxEnd = \Illuminate\Support\Carbon::parse($employee->date_of_joining ?? today())->addMonthsNoOverflow(12)->subDay();
        $data = $request->validate([
            'decision' => ['required', Rule::in(['confirm', 'extend', 'terminate'])],
            'comment' => ['required', 'string', 'max:3000'],
            'extension_end_date' => ['required_if:decision,extend', 'nullable', 'date', 'after:'.$currentEnd->toDateString(), 'before_or_equal:'.$maxEnd->toDateString()],
            'exit_date' => ['nullable', 'date'],
        ], [
            'extension_end_date.after' => 'The new end date must be after the current probation end date ('.$currentEnd->format('j M Y').'). Shortening a probation is not an extension.',
            'extension_end_date.before_or_equal' => 'Probation cannot run past '.$maxEnd->format('j M Y').': 12 months from joining is the legal maximum (Employment Act s.42).',
        ]);
        abort_if($data['decision'] === 'extend' && $employee->probation_status === 'extended', 422,
            'This probation has already been extended once. The Employment Act allows a single extension; confirm or end it.');
        $decision = $data['decision'];
        $status = ['confirm' => 'confirmed', 'extend' => 'extended', 'terminate' => 'terminated'][$decision];
        $decidedOn = today()->toDateString();
        $extra = [];

        DB::transaction(function () use ($request, $probationReview, $employee, $data, $decision, $status, $decidedOn, &$extra) {
            $probationReview->update([
                'decision' => $decision,
                'hr_comment' => $data['comment'],
                'extension_end_date' => $data['extension_end_date'] ?? null,
                'hr_reviewed_by' => $request->user()->id,
                'hr_reviewed_at' => now(),
                'status' => $status,
            ]);

            if ($decision === 'terminate') {
                // Exit through the lifecycle so EmployeeExited fires (payroll final dues, leave, approvals re-routing...).
                $employee->update(['probation_status' => 'terminated']);
                $exit = app(LifecycleService::class)->recordExit($employee, [
                    'exit_date' => $data['exit_date'] ?? $decidedOn,
                    'notice_date' => $decidedOn,
                    'reason' => 'termination',
                    'comments' => 'Probation terminated: '.$data['comment'],
                ], $request->user());
                $extra['exit_id'] = $exit->id;
            } elseif ($decision === 'extend') {
                $employee->update(['probation_status' => 'extended', 'probation_end_date' => $data['extension_end_date']]);
                // A fresh review cycle for the extended period.
                $next = $this->routing()->open($employee->fresh(), $data['extension_end_date']);
                $extra['next_review_id'] = $next->id;
            } else {
                $employee->update(['probation_status' => 'confirmed']);
            }

            activity('probation')->performedOn($employee)->causedBy($request->user())
                ->withProperties(['review_id' => $probationReview->id, 'decision' => $decision, 'comment' => $data['comment'],
                    'extension_end_date' => $data['extension_end_date'] ?? null] + $extra)
                ->log("Probation {$status}");
        });

        // Confirmation letter (generated outside the transaction; a PDF failure must not undo the decision).
        if ($decision === 'confirm') {
            try {
                $letter = app(LetterService::class)->generate($employee->fresh(), 'probation_confirmation', $decidedOn, null, $request->user());
                $extra['letter_url'] = '/api/employees/letters/'.$letter->id.'/download';
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $message = ['confirm' => 'Probation confirmed'.(isset($extra['letter_url']) ? ' and the confirmation letter generated.' : '.'),
            'extend' => 'Probation extended to '.\Illuminate\Support\Carbon::parse($data['extension_end_date'] ?? $decidedOn)->format('j M Y').'. A new review cycle has started.',
            'terminate' => 'Probation terminated and the exit recorded.'][$decision];

        return response()->json(['message' => $message, 'data' => $this->presentReview($probationReview->fresh()) + $extra]);
    }

    private function routing(): ProbationRouting
    {
        return app(ProbationRouting::class);
    }

    private function presentReview(ProbationReview $r): array
    {
        $routing = $this->routing();
        $user = request()->user();
        $steps = ProbationRouting::steps($r);
        $log = collect($r->stage_log ?? []);

        return [
            'id' => $r->id,
            'employee_id' => $r->employee_id,
            'cycle' => $r->cycle,
            'probation_end_date' => $r->probation_end_date?->toDateString(),
            'status' => $r->status,
            'stage_index' => $r->stage_index,
            'current_stage' => ($step = $routing->currentStep($r)) ? ApprovalChain::label($step) : ($r->status === 'hr_review' ? 'HR decision' : null),
            // A stage's state follows its latest log entry, so a skipped stage that was reopened shows as current / done.
            'stages' => collect($steps)->map(function ($s, $i) use ($r, $log) {
                $entry = $log->last(fn ($l) => ($l['stage'] ?? null) === $i && empty($l['closed']));
                $open = in_array($r->status, ProbationRouting::OPEN, true);

                return [
                    'label' => ApprovalChain::label($s),
                    'state' => match (true) {
                        $open && $i === $r->stage_index => 'current',
                        ! empty($entry['skipped']) => 'skipped',
                        isset($entry['comment']) => 'done',
                        $r->status === ProbationRouting::CANCELLED => 'waiting',
                        ! $open || $i < $r->stage_index => 'done',
                        default => 'waiting',
                    },
                    'entry' => $entry,
                ];
            })->values(),
            // Earliest date HR may extend to: the day after the later of the current end date and today.
            'min_extension_date' => collect([$r->employee?->probation_end_date, $r->probation_end_date, today()])->filter()->max()->copy()->addDay()->toDateString(),
            'max_extension_date' => \Illuminate\Support\Carbon::parse($r->employee?->date_of_joining ?? today())->addMonthsNoOverflow(12)->subDay()->toDateString(),
            'can_extend' => $r->employee?->probation_status !== 'extended',
            'can_review' => $user ? $routing->canDecide($r, $user) : false,
            'can_decide' => $r->status === 'hr_review' && $user?->can('probation.manage') && $user->employee?->id !== $r->employee_id,
            'primary_approver_id' => $r->primary_approver_id,
            'decision' => $r->decision,
            'extension_end_date' => $r->extension_end_date?->toDateString(),
            'primary_comment' => $r->primary_comment,
            'primary_reviewer' => $r->primaryReviewer?->name,
            'primary_reviewed_at' => $r->primary_reviewed_at,
            'secondary_comment' => $r->secondary_comment,
            'secondary_reviewer' => $r->secondaryReviewer?->name,
            'secondary_reviewed_at' => $r->secondary_reviewed_at,
            'hoo_comment' => $r->hoo_comment,
            'hoo_reviewer' => $r->hooReviewer?->name,
            'hoo_reviewed_at' => $r->hoo_reviewed_at,
            'hr_comment' => $r->hr_comment,
            'hr_reviewer' => $r->hrReviewer?->name,
            'hr_reviewed_at' => $r->hr_reviewed_at,
            // The confirmation letter generated with this decision (also listed in the employee's Documents).
            'letter_url' => $r->decision === 'confirm' && ($letterId = EmployeeLetter::where('employee_id', $r->employee_id)
                ->where('template', 'probation_confirmation')->when($r->hr_reviewed_at, fn ($q) => $q->where('created_at', '>=', $r->hr_reviewed_at->copy()->subMinute()))
                ->latest('id')->value('id')) ? '/api/employees/letters/'.$letterId.'/download' : null,
        ];
    }
}
