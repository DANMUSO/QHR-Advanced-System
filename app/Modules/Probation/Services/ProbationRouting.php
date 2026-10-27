<?php

namespace App\Modules\Probation\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Organization\Support\ApprovalChain;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Notifications\ProbationNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Probation review stages. The chain is the company's configured Workflow for 'probation_end' (ApprovalChain), else
 * the built-in Primary supervisor → Secondary supervisor → Head of Operations (department head); HR (probation.manage)
 * then decides. The chain is snapshotted on the review (stages) and stage_index points at the stage awaiting a comment.
 *
 * Stages run strictly in order and nobody, HR included, can skip one. The only exception: a stage with no reachable
 * person (ApproverResolver escalates up the reporting line first) is skipped automatically with a logged note.
 * primary_approver_id holds the employee who owes the current stage (person-based steps), for inboxes and re-routing.
 */
class ProbationRouting
{
    /** Reviewer stages still open (HR has not been reached yet). */
    public const OPEN = ['pending', 'in_review'];

    /** Closed without a decision (the employee left or moved on before the review finished). */
    public const CANCELLED = 'cancelled';

    public const FINAL = ['confirmed', 'extended', 'terminated', self::CANCELLED];

    /** Still waiting on someone: a reviewer stage or the HR decision. */
    public const UNDECIDED = ['pending', 'in_review', 'hr_review'];

    public const BUILT_IN = [
        ['type' => 'supervisor', 'label' => 'Primary supervisor review'],
        ['type' => 'secondary_supervisor', 'label' => 'Secondary supervisor check'],
        ['type' => 'hod', 'label' => 'Head of Operations check'],
    ];

    /** Legacy per-stage columns kept filled for the first three stages (primary_comment, secondary_…, hoo_…). */
    private const COLUMNS = ['primary', 'secondary', 'hoo'];

    public const HR_PERMISSION = 'probation.manage';

    public function __construct(private ApproverResolver $resolver) {}

    /** Creates a review (new cycle) for the employee and routes it to the first reachable stage. */
    public function open(Employee $employee, ?string $endDate = null, bool $notify = true): ProbationReview
    {
        $cycle = (int) ProbationReview::withoutGlobalScopes()->where('employee_id', $employee->id)->max('cycle') + 1;
        $steps = ApprovalChain::steps((int) $employee->company_id, 'probation_end') ?? self::BUILT_IN;

        $review = (new ProbationReview([
            'employee_id' => $employee->id,
            'probation_end_date' => $endDate ?? $employee->probation_end_date ?? today(),
            'status' => 'pending',
            'stage_index' => 0,
            'stages' => array_values($steps),
            'stage_log' => [],
            'cycle' => max(1, $cycle),
        ]))->forceFill(['company_id' => $employee->company_id]);
        $review->save();
        $this->advance($review, $notify);

        return $review;
    }

    /** @return array<int, array> the review's stage chain (snapshot, or the built-in chain for old rows) */
    public static function steps(ProbationReview $review): array
    {
        return $review->stages ?: self::BUILT_IN;
    }

    public function currentStep(ProbationReview $review): ?array
    {
        return in_array($review->status, self::OPEN, true) ? (self::steps($review)[$review->stage_index] ?? null) : null;
    }

    /** Employee who owes a person-based step (escalated past people without a usable login); null for role steps. */
    public function approverFor(ProbationReview $review, array $step): ?Employee
    {
        $subject = $this->subject($review);
        $personId = match ($step['type'] ?? null) {
            'supervisor' => $subject?->primary_supervisor_id,
            'secondary_supervisor' => $subject?->secondary_supervisor_id,
            'hod' => $subject?->department()->withoutGlobalScopes()->value('head_employee_id'),
            default => null,
        };

        return $personId ? $this->resolver->resolve((int) $personId, [$review->employee_id]) : null;
    }

    /** Users who may act now: the current stage's people, or HR once every stage is done. */
    public function recipients(ProbationReview $review): Collection
    {
        $subject = $this->subject($review);
        if ($review->status === 'hr_review') {
            return $this->resolver->fallbackUsers((int) $review->company_id, self::HR_PERMISSION, [$subject?->user_id]);
        }
        $step = $this->currentStep($review);
        if (! $step || ! $subject) {
            return collect();
        }
        if (($step['type'] ?? null) === 'role') {
            return ApprovalChain::usersFor($subject, $step);
        }
        $approver = $this->approverFor($review, $step);

        return $approver?->user && ApproverResolver::isReachable($approver) ? collect([$approver->user]) : collect();
    }

    /**
     * Moves to the first stage that has someone to act (skipping, with a logged note, stages nobody can act on) or to
     * HR once the chain is done. Stores the current approver and notifies. Returns true when anything changed.
     */
    public function advance(ProbationReview $review, bool $notify = true): bool
    {
        if (! in_array($review->status, self::OPEN, true)) {
            return false;
        }
        $before = $review->only(['stage_index', 'status', 'primary_approver_id']);
        $steps = self::steps($review);
        $log = (array) ($review->stage_log ?? []);

        while (true) {
            $step = $steps[$review->stage_index] ?? null;
            if ($step && self::stageDone($log, (int) $review->stage_index)) {
                $review->stage_index++; // already commented on (a reopened earlier stage was just completed)
                continue;
            }
            if (! $step) {
                $review->status = 'hr_review';
                $review->primary_approver_id = null;
                break;
            }
            $review->status = $review->stage_index === 0 && ! $log ? 'pending' : 'in_review';
            if ($this->recipients($review)->isNotEmpty()) {
                $review->primary_approver_id = ($step['type'] ?? null) === 'role' ? null : $this->approverFor($review, $step)?->id;
                break;
            }
            $label = ApprovalChain::label($step);
            $log[] = ['stage' => $review->stage_index, 'label' => $label, 'skipped' => true, 'note' => 'Skipped: nobody can act on this stage', 'at' => now()->toIso8601String()];
            activity('probation')->performedOn($review)->withProperties(['employee_id' => $review->employee_id, 'stage' => $label])
                ->log("Probation stage skipped (nobody reachable): {$label}");
            $review->stage_index++;
            $review->status = 'in_review';
        }
        $review->stage_log = $log;
        $review->save();

        $changed = $before != $review->only(['stage_index', 'status', 'primary_approver_id']);
        if ($changed && $notify) {
            $this->notify($review);
        }

        return $changed;
    }

    /** Whether the stage's latest log entry is a submitted comment (not a skip). */
    private static function stageDone(array $log, int $index): bool
    {
        $last = collect($log)->where('stage', $index)->last();

        return $last && ! ($last['skipped'] ?? false) && ! ($last['reopened'] ?? false);
    }

    /**
     * A stage skipped because nobody could act (e.g. the supervisor had no login yet) is reopened as soon as someone
     * can: the earliest such stage becomes current again and the stages already commented on are not asked twice.
     * Works for reviews still at a reviewer stage or already waiting for HR. Returns true when a stage was reopened.
     */
    public function reopenSkipped(ProbationReview $review, bool $notify = true): bool
    {
        if (! in_array($review->status, self::UNDECIDED, true)) {
            return false;
        }
        $log = (array) ($review->stage_log ?? []);
        $skipped = collect($log)->filter(fn ($e) => $e['skipped'] ?? false)->pluck('stage')->unique()->sort()
            ->filter(fn ($i) => $i < $review->stage_index || $review->status === 'hr_review')
            ->filter(fn ($i) => (collect($log)->where('stage', $i)->last()['skipped'] ?? false));

        foreach ($skipped as $index) {
            $probe = (clone $review)->forceFill(['stage_index' => (int) $index, 'status' => 'in_review']);
            if ($this->recipients($probe)->isEmpty()) {
                continue;
            }
            $label = ApprovalChain::label(self::steps($review)[$index] ?? []);
            $log[] = ['stage' => (int) $index, 'label' => $label, 'reopened' => true, 'note' => 'Reopened: a reviewer can now act on this stage', 'at' => now()->toIso8601String()];
            $review->forceFill(['stage_index' => (int) $index, 'status' => 'in_review', 'stage_log' => $log]);
            activity('probation')->performedOn($review)->withProperties(['employee_id' => $review->employee_id, 'stage' => $label])
                ->log("Probation stage reopened: {$label}");
            $this->advance($review, $notify);

            return true;
        }

        return false;
    }

    /** Closes the employee's undecided reviews (exit, transfer) so they leave every queue. Returns how many. */
    public function closeForEmployee(int $employeeId, string $reason): int
    {
        $reviews = ProbationReview::withoutGlobalScopes()->where('employee_id', $employeeId)->whereIn('status', self::UNDECIDED)->get();
        foreach ($reviews as $review) {
            $log = (array) ($review->stage_log ?? []);
            $log[] = ['stage' => (int) $review->stage_index, 'label' => 'Closed', 'closed' => true, 'note' => "Closed: {$reason}", 'at' => now()->toIso8601String()];
            $review->forceFill(['status' => self::CANCELLED, 'primary_approver_id' => null, 'stage_log' => $log,
                'hr_comment' => $review->hr_comment ?: "Closed: {$reason}"])->save();
            activity('probation')->performedOn($review)->withProperties(['employee_id' => $employeeId, 'reason' => $reason])
                ->log("Probation review closed: {$reason}");
        }

        return $reviews->count();
    }

    /** Re-routing (approvals:reroute / exits): re-resolve the current stage's approver. */
    public function assign(ProbationReview $review, bool $notify = true): bool
    {
        if (! in_array($review->status, self::OPEN, true)) {
            return false;
        }
        $step = $this->currentStep($review);
        $new = $step && ($step['type'] ?? null) !== 'role' ? $this->approverFor($review, $step)?->id : null;
        if ($step && $this->recipients($review)->isNotEmpty() && (int) $new === (int) $review->primary_approver_id) {
            return false;
        }
        $from = $review->primary_approver_id;
        $changed = $this->advance($review, $notify);
        if ($changed) {
            activity('probation')->performedOn($review)
                ->withProperties(['from' => $from, 'to' => $review->primary_approver_id, 'employee_id' => $review->employee_id])
                ->log('Probation reviewer routed');
        }

        return $changed;
    }

    /** Whether the user is the person (or role) for the current stage. HR cannot act for a reviewer. */
    public function canDecide(ProbationReview $review, User $user): bool
    {
        $step = $this->currentStep($review);
        if (! $step) {
            return false;
        }
        $me = $user->employee;
        if ($me && (int) $me->id === (int) $review->employee_id) {
            return false;
        }
        if ($user->hasRole('super-admin')) {
            return true;
        }

        return $this->recipients($review)->contains(fn (User $u) => $u->is($user));
    }

    /** Back-compat name used by older callers. */
    public function canPrimaryReview(ProbationReview $review, User $user): bool
    {
        return $this->canDecide($review, $user);
    }

    /** Records the current stage's comment and moves on. */
    public function decide(ProbationReview $review, User $user, string $comment): void
    {
        $step = $this->currentStep($review);
        $index = (int) $review->stage_index;
        $label = ApprovalChain::label($step ?? []);
        $log = (array) ($review->stage_log ?? []);
        $log[] = ['stage' => $index, 'label' => $label, 'comment' => $comment, 'by' => $user->id, 'by_name' => $user->name, 'at' => now()->toIso8601String()];

        if ($column = self::COLUMNS[$index] ?? null) {
            $review->forceFill(["{$column}_comment" => $comment, "{$column}_reviewed_by" => $user->id, "{$column}_reviewed_at" => now()]);
        }
        $review->stage_log = $log;
        $review->stage_index = $index + 1;
        $review->status = 'in_review';
        $review->save();

        activity('probation')->performedOn($review)->causedBy($user)
            ->withProperties(['employee_id' => $review->employee_id, 'stage' => $label, 'comment' => $comment])
            ->log("Probation review: {$label} submitted");

        $this->advance($review);
    }

    /** Open reviews whose current stage this user can act on (the Team "Probation reviews" page and the inbox). */
    public function queueFor(User $user, int $limit = 200): Collection
    {
        $meId = $user->employee?->id;

        return ProbationReview::whereIn('status', self::OPEN)
            ->when($meId, fn ($q) => $q->where('employee_id', '!=', $meId))
            ->when(! $user->hasRole('super-admin'), fn ($q) => $q->where(fn ($w) => $w->whereNull('primary_approver_id')
                ->when($meId, fn ($x) => $x->orWhere('primary_approver_id', $meId))))
            ->with('employee')->orderBy('probation_end_date')->limit($limit)->get()
            ->filter(fn (ProbationReview $r) => $this->canDecide($r, $user))->values();
    }

    /** Reviews waiting for the HR decision. */
    public static function hrQueue(User $user)
    {
        return $user->can(self::HR_PERMISSION) ? ProbationReview::where('status', 'hr_review') : null;
    }

    private function subject(ProbationReview $review): ?Employee
    {
        return Employee::withoutGlobalScopes()->with('user')->find($review->employee_id);
    }

    private function notify(ProbationReview $review): void
    {
        $employee = $this->subject($review);
        if (! $employee) {
            return;
        }
        try {
            $hr = $review->status === 'hr_review';
            $step = $this->currentStep($review);
            Notification::send($this->recipients($review), new ProbationNotification(
                $hr ? 'Probation decision due' : 'Probation review due',
                $hr
                    ? "All review stages for {$employee->full_name} are complete. Confirm, extend or terminate the probation."
                    : 'Please submit the '.strtolower(ApprovalChain::label($step ?? [])).' for '.$employee->full_name.' (probation ends '.($review->probation_end_date?->format('d M Y') ?? 'soon').').',
                $hr ? '/probation' : '/team/probation',
            ));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
