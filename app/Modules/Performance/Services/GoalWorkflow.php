<?php

namespace App\Modules\Performance\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Organization\Support\ApprovalChain;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\GoalEvent;
use App\Modules\Performance\Models\GoalRating;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Notifications\PerformanceNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * KPI goal lifecycle:
 *   cascaded → (accept | cascade down | convert to self) → in_progress → submitted → approved
 *   cascaded/in_progress → under_review → (amend → cascaded) | (reject review → cascaded)
 *   submitted → reject → in_progress (score reset)
 * Approving the last open subordinate goal rolls the parent goal up and auto-submits it to its own approver
 * (or approves it outright when nobody sits above it, i.e. the creator's own item is final).
 *
 * When the company configures a "kpi_review" approval chain (Workflow designer), submitted results and review
 * requests are decided step by step by the chain (approval_step) instead of the single approver_id.
 */
class GoalWorkflow
{
    // ---------------------------------------------------------------- assignment

    /**
     * Assign a KPI from a plan.
     *
     * @param  array<int, array{employee_id: int, target?: float|null}>  $assignees
     * @return array<int, Goal>
     */
    public function assignFromKpi(Kpi $kpi, User $user, string $mode, ?string $split, array $assignees, ?string $dueDate = null): array
    {
        $kpi->loadMissing('plan.cycle', 'unit', 'objective');
        $actor = $user->employee;
        $plan = $kpi->plan;

        if (! ScoreCalculator::weightsComplete($plan->objectives()->pluck('weight')->map(fn ($w) => (float) $w)->all())) {
            throw ValidationException::withMessages(['plan' => "Objective weights in \"{$plan->name}\" must total 100% before KPIs can be assigned (currently {$plan->totalWeight()}%)."]);
        }
        if ($plan->cycle->status === 'closed') {
            throw ValidationException::withMessages(['plan' => 'This review cycle is closed.']);
        }

        $due = $dueDate ?: $kpi->due_date->toDateString();
        $plan->cycle->assertDateWithin($due);
        $target = $this->kpiTarget($kpi);

        if ($mode === 'self') {
            $ownerId = (int) ($assignees[0]['employee_id'] ?? $actor?->id);
            if (! $ownerId) {
                throw ValidationException::withMessages(['assignees' => 'Choose the employee who owns this KPI.']);
            }
            if ($ownerId !== $actor?->id && ! PerformanceAccess::isAdmin($user) && ! PerformanceAccess::canManage($user, $ownerId)) {
                abort(403, 'You can only assign self-owned KPIs to yourself or your team.');
            }
            $owner = Employee::findOrFail($ownerId);
            $this->assertNoDuplicate($kpi->id, $owner->id, null);

            return [DB::transaction(function () use ($kpi, $owner, $target, $due, $user) {
                $goal = $this->newGoal($kpi, $owner, [
                    'mode' => 'self', 'split' => null, 'target' => $target, 'due_date' => $due, 'status' => 'in_progress',
                    'assigned_by' => $user->employee?->id, 'approver_id' => $owner->primary_supervisor_id, 'accepted_at' => now(),
                ]);
                $this->log($goal, $user, 'created', null, ['mode' => 'self', 'target' => $target]);

                return $goal;
            })];
        }

        // Cascading
        $split = $kpi->isQualitative() ? 'equal' : ($split ?: 'equal');
        $ids = collect($assignees)->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['assignees' => 'Select at least one employee.']);
        }
        if (! PerformanceAccess::isAdmin($user)) {
            $direct = $actor ? PerformanceAccess::directReportIds($actor->id) : collect();
            if ($ids->diff($direct)->isNotEmpty()) {
                throw ValidationException::withMessages(['assignees' => 'You can only cascade KPIs to your direct reports.']);
            }
        }

        $shares = $this->shares($split, $assignees, $target);
        if ($split === 'distributed') {
            $allocated = (float) Goal::where('kpi_id', $kpi->id)->whereNull('parent_goal_id')->where('mode', 'cascade')
                ->where('split', 'distributed')->sum('target');
            if ($error = ScoreCalculator::distributionError((float) $target, array_values($shares), $kpi->isPercentage(), $allocated)) {
                throw ValidationException::withMessages(['assignees' => $error]);
            }
        }

        $employees = Employee::whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            abort_unless($employees->has($id), 422, 'Unknown employee.');
            $this->assertNoDuplicate($kpi->id, $id, null);
        }

        return DB::transaction(function () use ($kpi, $employees, $shares, $split, $due, $user, $actor) {
            $goals = [];
            foreach ($employees as $id => $employee) {
                $goal = $this->newGoal($kpi, $employee, [
                    'mode' => 'cascade', 'split' => $split, 'target' => $shares[$id], 'due_date' => $due, 'status' => 'cascaded',
                    'assigned_by' => $actor?->id,
                    'approver_id' => $actor && $employee->primary_supervisor_id === $actor->id ? $actor->id : ($employee->primary_supervisor_id ?? $actor?->id),
                ]);
                $this->log($goal, $user, 'cascaded', null, ['target' => $shares[$id], 'split' => $split]);
                $this->notify($employee, 'New KPI assigned', "{$this->actorName($user)} assigned you the KPI \"{$kpi->name}\" (target {$this->fmt($shares[$id])}, due {$goal->due_date->format('d M Y')}). Please accept it or request a review.", $goal);
                $goals[] = $goal;
            }

            return $goals;
        });
    }

    /**
     * The goal owner pushes their goal down to their own direct reports.
     *
     * @param  array<int, array{employee_id: int, target?: float|null}>  $assignees
     * @return array<int, Goal>
     */
    public function cascadeGoal(Goal $parent, User $user, ?string $split, array $assignees): array
    {
        $this->assertOwner($parent, $user);
        if (! in_array($parent->status, ['cascaded', 'in_progress'], true)) {
            throw ValidationException::withMessages(['status' => 'Only open goals can be cascaded further.']);
        }
        $parent->loadMissing('kpi.unit');
        $kpi = $parent->kpi;
        $split = $kpi->isQualitative() ? 'equal' : ($split ?: 'equal');
        $ids = collect($assignees)->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values();
        $direct = PerformanceAccess::directReportIds($user->employee->id);
        if ($ids->isEmpty() || $ids->diff($direct)->isNotEmpty()) {
            throw ValidationException::withMessages(['assignees' => 'Select one or more of your direct reports.']);
        }
        $target = (float) $parent->target;
        $shares = $this->shares($split, $assignees, $target);
        if ($split === 'distributed') {
            $allocated = (float) $parent->children()->sum('target');
            if ($error = ScoreCalculator::distributionError($target, array_values($shares), $kpi->isPercentage(), $allocated)) {
                throw ValidationException::withMessages(['assignees' => $error]);
            }
        }
        foreach ($ids as $id) {
            $this->assertNoDuplicate($kpi->id, $id, $parent->id);
        }

        return DB::transaction(function () use ($parent, $kpi, $ids, $shares, $split, $user) {
            $goals = [];
            foreach (Employee::whereIn('id', $ids)->get() as $employee) {
                $goal = $this->newGoal($kpi, $employee, [
                    'parent_goal_id' => $parent->id, 'mode' => 'cascade', 'split' => $split, 'target' => $shares[$employee->id],
                    'due_date' => $parent->due_date->toDateString(), 'status' => 'cascaded',
                    'assigned_by' => $user->employee->id, 'approver_id' => $user->employee->id, 'cycle_id' => $parent->cycle_id,
                ]);
                $this->log($goal, $user, 'cascaded', null, ['target' => $shares[$employee->id], 'split' => $split, 'from_goal' => $parent->id]);
                $this->notify($employee, 'New KPI assigned', "{$this->actorName($user)} cascaded the KPI \"{$kpi->name}\" to you (target {$this->fmt($shares[$employee->id])}).", $goal);
                $goals[] = $goal;
            }
            if ($parent->status === 'cascaded') {
                $parent->forceFill(['status' => 'in_progress', 'accepted_at' => $parent->accepted_at ?? now()])->save();
            }
            $this->log($parent, $user, 'cascaded_down', null, ['to' => collect($goals)->map(fn ($g) => $g->employee_id)->all(), 'split' => $split]);

            return $goals;
        });
    }

    // ---------------------------------------------------------------- employee actions

    public function accept(Goal $goal, User $user): Goal
    {
        $this->assertOwner($goal, $user);
        $this->assertStatus($goal, ['cascaded'], 'Only newly cascaded goals can be accepted.');
        $goal->forceFill(['status' => 'in_progress', 'accepted_at' => now()])->save();
        $this->log($goal, $user, 'accepted');

        return $goal;
    }

    public function convertToSelf(Goal $goal, User $user): Goal
    {
        $this->assertOwner($goal, $user);
        $this->assertStatus($goal, ['cascaded'], 'Only newly cascaded goals can be converted.');
        $goal->forceFill(['mode' => 'self', 'status' => 'in_progress', 'accepted_at' => now()])->save();
        $this->log($goal, $user, 'converted_self');

        return $goal;
    }

    public function requestReview(Goal $goal, User $user, string $reason): Goal
    {
        $this->assertOwner($goal, $user);
        $this->assertStatus($goal, ['cascaded', 'in_progress'], 'A review can only be requested on an open goal.');
        $goal->forceFill(['status' => 'under_review', 'review_reason' => $reason])->save();
        $this->log($goal, $user, 'review_requested', $reason);
        if ($this->chain($goal)) {
            $goal->forceFill(['approval_step' => 0])->save();
            $this->routeToChain($goal, 'KPI review requested', "{$goal->employee->full_name} asked for a review of the KPI \"{$goal->kpi->name}\": {$reason}");
        } elseif ($goal->approver) {
            $this->notify($goal->approver, 'KPI review requested', "{$goal->employee->full_name} asked you to review the KPI \"{$goal->kpi->name}\": {$reason}", $goal, '/performance/manage?tab=approvals');
        }

        return $goal;
    }

    /**
     * @param  array<int, int>|null  $ratings  question_id => rating (qualitative KPIs)
     */
    public function submit(Goal $goal, User $user, ?float $achieved, ?string $comment, ?UploadedFile $evidence = null, ?array $ratings = null): Goal
    {
        $this->assertOwner($goal, $user);
        $this->assertStatus($goal, ['in_progress'], $goal->status === 'cascaded' ? 'Accept the goal before submitting a score.' : 'This goal is not open for submission.');
        $goal->loadMissing('kpi.questions', 'kpi.objective', 'cycle');

        if ($goal->children()->exists()) {
            $children = $goal->children()->get();
            if ($children->contains(fn ($c) => $c->status !== 'approved')) {
                throw ValidationException::withMessages(['achieved' => 'This KPI was cascaded to your team. It is submitted automatically once all of their scores are approved.']);
            }
            $achieved = $this->rollupValue($goal, $children);
        } elseif ($goal->kpi->isQualitative()) {
            $questionIds = $goal->kpi->questions->pluck('id');
            $max = SetupService::maxScale($goal->company_id);
            $ratings = collect($ratings ?? [])->mapWithKeys(fn ($r, $q) => [(int) $q => (int) $r]);
            if ($questionIds->isEmpty() || $questionIds->diff($ratings->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['ratings' => 'Rate every question.']);
            }
            if ($ratings->contains(fn ($r) => $r < 1 || $r > $max)) {
                throw ValidationException::withMessages(['ratings' => "Ratings must be between 1 and {$max}."]);
            }
            $goal->ratings()->delete();
            foreach ($questionIds as $qid) {
                GoalRating::create(['goal_id' => $goal->id, 'question_id' => $qid, 'rating' => $ratings[$qid]]);
            }
            $achieved = round($ratings->only($questionIds->all())->avg(), 2);
        } elseif ($achieved === null) {
            throw ValidationException::withMessages(['achieved' => 'Enter the achieved value.']);
        }

        if ($evidence) {
            $goal->evidence_path = $evidence->store("performance/evidence/{$goal->id}", 'local');
            $goal->evidence_name = $evidence->getClientOriginalName();
        }
        $goal->fill(['achieved' => $achieved, 'employee_comment' => $comment, 'status' => 'submitted', 'submitted_at' => now(), 'rejection_reason' => null, 'approval_step' => 0]);
        $this->computeScore($goal);
        $goal->save();
        $this->log($goal, $user, 'submitted', $comment, ['achieved' => $achieved]);

        if ($this->chain($goal)) {
            if (! $this->routeToChain($goal, 'KPI submitted for approval', "{$goal->employee->full_name} submitted {$this->fmt($achieved)} against a target of {$this->fmt($goal->target)} for \"{$goal->kpi->name}\".")) {
                return $this->approve($goal, $user, 'Final — nobody in the approval chain can decide this goal.', system: true);
            }

            return $goal;
        }

        if (! $goal->approver_id) {
            return $this->approve($goal, $user, 'Final — no supervisor above this goal.', system: true);
        }
        $this->escalateApprover($goal);
        $this->notifyApprover($goal, 'KPI submitted for approval', "{$goal->employee->full_name} submitted {$this->fmt($achieved)} against a target of {$this->fmt($goal->target)} for \"{$goal->kpi->name}\".", $goal, '/performance/manage?tab=approvals');

        return $goal;
    }

    public function comment(Goal $goal, User $user, string $comment): GoalEvent
    {
        return $this->log($goal, $user, 'comment', $comment);
    }

    // ---------------------------------------------------------------- supervisor actions

    public function approve(Goal $goal, User $user, ?string $comment = null, bool $system = false): Goal
    {
        if (! $system) {
            $this->assertApprover($goal, $user);
        }
        $this->assertStatus($goal, ['submitted'], 'Only submitted goals can be approved.');
        $goal->loadMissing('kpi.objective', 'cycle');
        if (! $system && ($steps = $this->chain($goal)) && (int) $goal->approval_step < count($steps) - 1) {
            $label = ApprovalChain::label($steps[(int) $goal->approval_step]);
            $goal->approval_step = (int) $goal->approval_step + 1;
            if ($this->routeToChain($goal, 'KPI awaiting your approval', "{$goal->employee->full_name}'s result for \"{$goal->kpi->name}\" was approved by {$label} and needs your decision.")) {
                $this->log($goal, $user, 'step_approved', $comment, ['step' => $label]);

                return $goal;
            }
        }
        $this->computeScore($goal);
        $goal->forceFill([
            'status' => 'approved', 'approved_at' => now(), 'approved_by' => $system ? null : $user->employee?->id,
            'supervisor_comment' => $comment ?? $goal->supervisor_comment,
        ])->save();
        $this->log($goal, $system ? null : $user, $system ? 'auto_approved' : 'approved', $comment, ['score' => $goal->score, 'achievement_pct' => $goal->achievement_pct]);
        if (! $system) {
            $this->notify($goal->employee, 'KPI approved', "Your result for \"{$goal->kpi->name}\" was approved ({$goal->achievement_pct}% achievement).", $goal);
        }
        activity()->performedOn($goal)->causedBy($system ? null : $user)->withProperties(['score' => $goal->score])->log('KPI goal approved');

        $this->rollupParent($goal, $user);

        return $goal;
    }

    public function reject(Goal $goal, User $user, string $reason): Goal
    {
        $this->assertApprover($goal, $user);
        $this->assertStatus($goal, ['submitted'], 'Only submitted goals can be rejected.');
        $goal->forceFill([
            'status' => 'in_progress', 'achievement_pct' => 0, 'score' => 0, 'rejection_reason' => $reason,
            'achieved' => $goal->children()->exists() ? $goal->achieved : null, 'submitted_at' => null, 'approval_step' => 0,
        ])->save();
        $this->log($goal, $user, 'rejected', $reason);
        $this->notify($goal->employee, 'KPI submission rejected', "Your submission for \"{$goal->kpi->name}\" was rejected: {$reason}. Please update and resubmit.", $goal);
        activity()->performedOn($goal)->causedBy($user)->withProperties(['reason' => $reason])->log('KPI goal rejected');

        return $goal;
    }

    public function extend(Goal $goal, User $user, string $dueDate, ?string $comment = null): Goal
    {
        $this->assertApprover($goal, $user);
        if ($goal->status === 'approved') {
            throw ValidationException::withMessages(['due_date' => 'Approved goals cannot be extended.']);
        }
        $old = $goal->due_date->toDateString();
        $goal->cycle?->assertDateWithin($dueDate);
        if (Carbon::parse($dueDate)->lte($goal->due_date)) {
            throw ValidationException::withMessages(['due_date' => 'The new due date must be after the current one ('.$goal->due_date->format('d M Y').').']);
        }
        $goal->forceFill(['due_date' => $dueDate, 'status' => $goal->status === 'submitted' ? 'in_progress' : $goal->status])->save();
        $this->log($goal, $user, 'extended', $comment, ['from' => $old, 'to' => $dueDate]);
        $this->notify($goal->employee, 'KPI deadline extended', "The due date for \"{$goal->kpi->name}\" moved to ".Carbon::parse($dueDate)->format('d M Y').'.', $goal);

        return $goal;
    }

    public function amend(Goal $goal, User $user, ?float $target, ?string $dueDate, ?string $comment): Goal
    {
        $this->assertApprover($goal, $user);
        $this->assertStatus($goal, ['under_review'], 'Only goals under review can be amended.');
        $goal->loadMissing('kpi.unit', 'parent');
        $old = ['target' => $goal->target, 'due_date' => $goal->due_date->toDateString()];
        $goal->cycle?->assertDateWithin($dueDate);
        if ($target !== null && $goal->split === 'distributed' && ! $goal->kpi->isPercentage()) {
            $siblings = Goal::where('kpi_id', $goal->kpi_id)->where('id', '!=', $goal->id)->where('split', 'distributed')
                ->where('parent_goal_id', $goal->parent_goal_id)->sum('target');
            $limit = $goal->parent ? (float) $goal->parent->target : (float) $goal->kpi->target;
            if ($error = ScoreCalculator::distributionError($limit, [$target], false, (float) $siblings)) {
                throw ValidationException::withMessages(['target' => $error]);
            }
        }
        $goal->forceFill([
            'target' => $target ?? $goal->target, 'due_date' => $dueDate ?? $goal->due_date, 'status' => 'cascaded', 'review_reason' => null,
        ])->save();
        $this->log($goal, $user, 'review_amended', $comment, ['from' => $old, 'to' => ['target' => $goal->target, 'due_date' => $goal->due_date->toDateString()]]);
        $this->notify($goal->employee, 'KPI amended after review', "Your supervisor amended \"{$goal->kpi->name}\" (target {$this->fmt($goal->target)}, due {$goal->due_date->format('d M Y')}). Please accept it.", $goal);

        return $goal;
    }

    public function rejectReview(Goal $goal, User $user, string $comment): Goal
    {
        $this->assertApprover($goal, $user);
        $this->assertStatus($goal, ['under_review'], 'This goal has no pending review request.');
        $goal->forceFill(['status' => 'cascaded', 'review_reason' => null])->save();
        $this->log($goal, $user, 'review_rejected', $comment);
        $this->notify($goal->employee, 'KPI review request declined', "Your review request for \"{$goal->kpi->name}\" was declined: {$comment}", $goal);

        return $goal;
    }

    public function withdraw(Goal $goal, User $user): void
    {
        $this->assertApprover($goal, $user);
        if ($goal->status !== 'cascaded' || $goal->children()->exists()) {
            throw ValidationException::withMessages(['status' => 'Only goals that have not been accepted (and not cascaded further) can be withdrawn.']);
        }
        activity()->performedOn($goal)->causedBy($user)->withProperties(['employee_id' => $goal->employee_id, 'kpi_id' => $goal->kpi_id])->log('KPI goal withdrawn');
        $goal->delete();
    }

    // ---------------------------------------------------------------- scoring & roll-up

    public function computeScore(Goal $goal): void
    {
        $goal->loadMissing('kpi.objective', 'cycle');
        $cap = (float) ($goal->cycle?->max_score_pct ?: 120);
        $pct = ScoreCalculator::achievementPct($goal->achieved, $goal->target, $cap);
        $goal->achievement_pct = $pct;
        $goal->score = ScoreCalculator::goalScore($pct, (float) ($goal->kpi->objective?->weight ?? 0));
    }

    public function rollupValue(Goal $parent, $children = null): ?float
    {
        $children ??= $parent->children()->get();
        $parent->loadMissing('kpi.unit');
        $split = $children->first()?->split;

        return ScoreCalculator::rollup(
            $children->where('status', 'approved')->pluck('achieved')->map(fn ($v) => $v === null ? null : (float) $v)->all(),
            ScoreCalculator::rollupAverages($parent->kpi->isPercentage(), $split),
        );
    }

    /** Recalculate a parent after a subordinate's approval; auto-submit it once every subordinate is approved. */
    public function rollupParent(Goal $goal, ?User $user): void
    {
        $parent = $goal->parent()->with('kpi.objective', 'cycle', 'employee', 'approver')->first();
        if (! $parent || $parent->status === 'approved') {
            return;
        }
        $children = $parent->children()->get();
        $parent->achieved = $this->rollupValue($parent, $children);
        $this->computeScore($parent);

        if ($children->every(fn ($c) => $c->status === 'approved') && in_array($parent->status, ['in_progress', 'cascaded'], true)) {
            $parent->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'accepted_at' => $parent->accepted_at ?? now(), 'approval_step' => 0])->save();
            $this->log($parent, null, 'auto_submitted', 'All subordinate results approved; rolled up automatically.', ['achieved' => $parent->achieved]);
            if ($this->chain($parent)) {
                if (! $this->routeToChain($parent, 'Team KPI ready for approval', "{$parent->employee->full_name}'s team result for \"{$parent->kpi->name}\" is ready for your approval.")) {
                    $this->approve($parent, $user ?? new User, 'Final — nobody in the approval chain can decide this goal.', system: true);
                }

                return;
            }
            if (! $parent->approver_id) {
                $this->approve($parent, $user ?? new User, 'Final — the creator owns this KPI.', system: true);

                return;
            }
            $this->escalateApprover($parent);
            $this->notifyApprover($parent, 'Team KPI ready for approval', "{$parent->employee->full_name}'s team result for \"{$parent->kpi->name}\" ({$this->fmt($parent->achieved)} of {$this->fmt($parent->target)}) is ready for your approval.", $parent, '/performance/manage?tab=approvals');
        } else {
            $parent->save();
        }
    }

    // ---------------------------------------------------------------- helpers

    public function log(Goal $goal, ?User $user, string $action, ?string $comment = null, ?array $meta = null): GoalEvent
    {
        return GoalEvent::create([
            // company_id from the goal: console jobs (approvals:reroute) have no tenant to stamp.
            'goal_id' => $goal->id, 'company_id' => $goal->company_id, 'user_id' => $user?->id, 'employee_id' => $user?->employee?->id,
            'action' => $action, 'comment' => $comment, 'meta' => $meta,
        ]);
    }

    public function notify(?Employee $employee, string $title, string $message, ?Goal $goal = null, ?string $url = null): void
    {
        $recipient = $employee?->user;
        if (! $recipient) {
            return;
        }
        try {
            $recipient->notify(new PerformanceNotification($title, $message, $url ?? ($goal ? "/performance?goal={$goal->id}" : '/performance')));
        } catch (\Throwable $e) {
            report($e); // a mail failure must not break the workflow
        }
    }

    // ---------------------------------------------------------------- approver routing (ApproverResolver)

    /**
     * If the goal's approver has no usable login (none / inactive / not activated), move it to the first reachable
     * supervisor above them. When nobody up the line is reachable the approver is kept (a null approver would mean
     * "final, auto-approve") and performance admins pick it up (approvals queue + notification). Returns true on change.
     */
    public function escalateApprover(Goal $goal): bool
    {
        if (! $goal->approver_id) {
            return false;
        }
        $current = Employee::withoutGlobalScopes()->with('user')->find($goal->approver_id);
        if (ApproverResolver::isReachable($current) && (int) $current->id !== (int) $goal->employee_id) {
            return false;
        }
        $next = app(ApproverResolver::class)->resolveId($current?->primary_supervisor_id, [$goal->employee_id]);
        if (! $next || (int) $next === (int) $goal->approver_id) {
            return false;
        }
        $from = $goal->approver_id;
        $goal->forceFill(['approver_id' => $next])->saveQuietly();
        $goal->unsetRelation('approver');
        $this->log($goal, null, 'approver_escalated', 'Approver had no usable login; escalated up the reporting line.', ['from' => $from, 'to' => $next]);

        return true;
    }

    /** Notify the goal's approver, or the company's performance admins when the approver cannot act. */
    public function notifyApprover(Goal $goal, string $title, string $message, string $url = '/performance/manage?tab=approvals'): void
    {
        $ownerUserId = Employee::withoutGlobalScopes()->whereKey($goal->employee_id)->value('user_id');
        $recipients = app(ApproverResolver::class)->recipients($goal->approver_id, (int) $goal->company_id, 'performance.admin', [$ownerUserId]);
        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new PerformanceNotification($title, $message, $url));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** approvals:reroute — escalate a submitted / under-review goal stuck with an unreachable approver. */
    public function reroute(Goal $goal): bool
    {
        if (! in_array($goal->status, ['submitted', 'under_review'], true) || ! $this->escalateApprover($goal)) {
            return false;
        }
        $goal->loadMissing('employee', 'kpi');
        $this->notifyApprover($goal, 'KPI awaiting your approval', ($goal->employee?->full_name ?? 'An employee')." has a KPI \"{$goal->kpi?->name}\" waiting for approval (re-routed to you).");

        return true;
    }

    public function isApprover(Goal $goal, User $user): bool
    {
        return PerformanceAccess::isAdmin($user) || $this->awaits($goal, $user);
    }

    /**
     * Whether the goal sits in this user's own queue (no admin override): the current kpi_review chain step while a
     * decision is pending, otherwise the goal's approver_id. Nobody decides their own goal.
     */
    public function awaits(Goal $goal, User $user): bool
    {
        if ($user->employee && $goal->employee_id === $user->employee->id) {
            return false;
        }
        if (in_array($goal->status, ['submitted', 'under_review'], true) && ($step = $this->currentStep($goal))) {
            $goal->loadMissing('employee.department');

            return $goal->employee !== null && ApprovalChain::canDecide($user, $goal->employee, $step);
        }

        return $user->employee && $goal->approver_id === $user->employee->id;
    }

    /** @var array<int, array|null> company_id => configured kpi_review steps */
    private static array $chains = [];

    /** The company's kpi_review approval chain, or null for the built-in approver_id step. */
    public function chain(Goal $goal): ?array
    {
        $companyId = (int) $goal->company_id;
        if (! $companyId) {
            return null;
        }
        if (! array_key_exists($companyId, self::$chains)) {
            self::$chains[$companyId] = ApprovalChain::steps($companyId, 'kpi_review');
        }

        return self::$chains[$companyId];
    }

    public function currentStep(Goal $goal): ?array
    {
        $steps = $this->chain($goal);

        return $steps ? $steps[min((int) $goal->approval_step, count($steps) - 1)] : null;
    }

    public static function flushChains(): void
    {
        self::$chains = [];
    }

    /**
     * Park the goal on the first chain step (from approval_step on) that somebody can decide, and notify them.
     * Returns false when no step has a decider left (the caller then treats the goal as final).
     */
    private function routeToChain(Goal $goal, string $title, string $message): bool
    {
        $steps = $this->chain($goal) ?? [];
        $goal->loadMissing('employee.department');
        for ($i = (int) $goal->approval_step; $i < count($steps); $i++) {
            $users = $goal->employee ? ApprovalChain::usersFor($goal->employee, $steps[$i]) : collect();
            if ($users->isEmpty()) {
                continue;
            }
            $goal->forceFill(['approval_step' => $i])->save();
            foreach ($users as $recipient) {
                try {
                    $recipient->notify(new PerformanceNotification($title, $message, '/performance/manage?tab=approvals'));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return true;
        }

        return false;
    }

    private function kpiTarget(Kpi $kpi): float
    {
        return $kpi->isQualitative() ? (float) SetupService::maxScale($kpi->company_id) : (float) $kpi->target;
    }

    /** @return array<int, float> employee_id => target */
    private function shares(string $split, array $assignees, float $target): array
    {
        $shares = [];
        foreach ($assignees as $a) {
            $shares[(int) $a['employee_id']] = $split === 'distributed' ? (float) ($a['target'] ?? 0) : $target;
        }

        return $shares;
    }

    private function newGoal(Kpi $kpi, Employee $employee, array $attributes): Goal
    {
        $goal = new Goal(array_merge(['kpi_id' => $kpi->id, 'employee_id' => $employee->id, 'cycle_id' => $kpi->plan->cycle_id], $attributes));
        $goal->company_id = $kpi->company_id;
        $goal->save();

        return $goal->setRelation('kpi', $kpi);
    }

    private function assertNoDuplicate(int $kpiId, int $employeeId, ?int $parentId): void
    {
        if (Goal::where('kpi_id', $kpiId)->where('employee_id', $employeeId)->exists()) {
            $name = Employee::find($employeeId)?->full_name ?? 'This employee';
            throw ValidationException::withMessages(['assignees' => "{$name} already has this KPI."]);
        }
    }

    private function assertOwner(Goal $goal, User $user): void
    {
        abort_unless($user->employee && $goal->employee_id === $user->employee->id, 403, 'This goal belongs to someone else.');
    }

    private function assertApprover(Goal $goal, User $user): void
    {
        abort_unless($this->isApprover($goal, $user), 403, 'Only the goal\'s supervisor can do this.');
    }

    private function assertStatus(Goal $goal, array $allowed, string $message): void
    {
        if (! in_array($goal->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function actorName(User $user): string
    {
        return $user->employee?->full_name ?? $user->name;
    }

    private function fmt(?float $n): string
    {
        return $n === null ? '—' : rtrim(rtrim(number_format($n, 2), '0'), '.');
    }
}
