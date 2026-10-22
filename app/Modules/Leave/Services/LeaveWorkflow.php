<?php

namespace App\Modules\Leave\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Support\ApproverResolver;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Leave\Events\LeaveDatesChanged;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Notifications\LeaveRequestNotification;
use App\Modules\Organization\Support\ApprovalChain;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Leave application workflow (HR-FEATURES §3.2–3.4):
 *   pending_handover → pending_supervisor → approved | rejected; handover_rejected; cancelled; recalled.
 *
 * The supervisor stage is either the built-in single decision by the primary supervisor, or — when the company
 * configured a "leave_request" approval chain (Organization → Workflows) — each chain step in order
 * (approval_step indexes the snapshot in approval_chain). Only the final step deducts the balance.
 */
class LeaveWorkflow
{
    public function __construct(private LeaveDayCounter $counter, private LeaveBalanceService $balances) {}

    // ------------------------------------------------------------------ validation & counting

    /**
     * Validate dates, eligibility, overlap and balance, and count the days.
     *
     * @param  bool  $reservePending  subtract other open requests from the available balance (on apply / edit)
     */
    public function assess(Employee $employee, LeaveType $type, Carbon $start, Carbon $end, bool $halfDay = false, ?string $startTime = null, ?string $endTime = null, ?LeaveRequest $existing = null, bool $checkBackdate = true, bool $reservePending = true): array
    {
        $company = $employee->company ?? Company::findOrFail($employee->company_id);
        $fail = fn (string $field, string $msg) => throw ValidationException::withMessages([$field => $msg]);

        if ((int) $type->company_id !== (int) $employee->company_id || ! $type->active) {
            $fail('leave_type_id', 'This leave type is not available.');
        }
        if (! $type->eligible($employee)) {
            $fail('leave_type_id', "{$type->name} is only available to ".($type->gender === 'female' ? 'female' : 'male').' employees.');
        }
        if ($end->lt($start)) {
            $fail('end_date', 'The end date must be on or after the start date.');
        }
        if ($halfDay) {
            if (! $type->allow_half_day) {
                $fail('half_day', "Half days are not allowed for {$type->name}.");
            }
            if (! $start->isSameDay($end)) {
                $fail('half_day', 'A half day must start and end on the same date.');
            }
        }
        $hourly = $type->allow_hours;
        if ($hourly) {
            if (! $startTime || ! $endTime) {
                $fail('start_time', 'Enter the from and to times for hourly leave.');
            }
            if (! $start->isSameDay($end)) {
                $fail('end_date', 'Hourly leave must start and end on the same date.');
            }
            $hours = LeaveDayCounter::hoursBetween($startTime, $endTime);
            if ($hours <= 0) {
                $fail('end_time', 'The to time must be after the from time.');
            }
            if ($hours > (float) $company->setting('hours_per_day', 8)) {
                $fail('end_time', 'Hourly leave cannot exceed one working day — apply for Annual leave instead.');
            }
        }
        if ($checkBackdate) {
            $limit = (int) $company->setting('leave_backdate_days', 7);
            if ($start->lt(today()->subDays($limit))) {
                $fail('start_date', "Leave cannot start more than {$limit} days in the past.");
            }
        }

        $count = $this->counter->forType($type, $company, $start, $end, $halfDay, $hourly ? $startTime : null, $hourly ? $endTime : null);
        if ($count['days'] <= 0) {
            $fail('start_date', $hourly ? 'The selected date is not a working day.' : 'The selected dates contain no working days.');
        }
        if ($type->max_consecutive && $count['days'] > $type->max_consecutive) {
            $fail('end_date', "{$type->name} is limited to {$type->max_consecutive} consecutive days per request.");
        }

        $overlap = LeaveRequest::withoutGlobalScopes()->where('employee_id', $employee->id)->blocking()
            ->overlapping($start->toDateString(), $end->toDateString())
            ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
            ->when($hourly, fn ($q) => $q->where(fn ($w) => $w->whereNull('start_time')->orWhere(fn ($t) => $t->where('start_time', '<', $endTime)->where('end_time', '>', $startTime))))
            ->with('leaveType:id,name')->first();
        if ($overlap) {
            $fail('start_date', "These dates overlap your {$overlap->leaveType?->name} request for {$overlap->start_date->format('j M')} – {$overlap->end_date->format('j M Y')}.");
        }

        // Maternity / paternity are separate gender-gated types; nobody may take both in one year.
        if (in_array(strtoupper((string) $type->code), LeaveType::PARENTAL_CODES, true)) {
            $other = LeaveRequest::withoutGlobalScopes()->where('employee_id', $employee->id)->blocking()
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->whereYear('start_date', $start->year)
                ->whereHas('leaveType', fn ($t) => $t->withoutGlobalScopes()->whereIn('code', array_diff(LeaveType::PARENTAL_CODES, [strtoupper((string) $type->code)])))
                ->exists();
            if ($other) {
                $fail('leave_type_id', 'Maternity and paternity leave cannot both be taken in the same year.');
            }
        }

        $balanceInfo = null;
        if ($type->tracksBalance()) {
            $ledger = $type->ledgerType();
            // Leave spanning 31 December draws each year's days from that year's balance.
            $portions = $this->yearPortions($type, $company, $start, $end, $halfDay, $hourly ? $startTime : null, $hourly ? $endTime : null, $count['days']);
            foreach ($portions as $year => $days) {
                $balance = $this->balances->ensure($employee, $ledger, $year);
                $available = $balance?->available ?? 0.0;
                $pending = $reservePending ? $this->balances->pendingDays($employee, $ledger, $year, $existing?->id) : 0.0;
                $usable = round($available - $pending, 2);
                $info = ['type' => $ledger->name, 'year' => $year, 'days' => $days, 'available' => $available, 'pending' => $pending, 'usable' => $usable, 'after' => round($usable - $days, 2)];
                $balanceInfo ??= $info;
                if (count($portions) > 1) {
                    $balanceInfo['split'][] = $info;
                }
                if ($days > $usable + 0.001) {
                    $fail('end_date', "Insufficient {$ledger->name} balance".(count($portions) > 1 ? " for $year" : '').": {$usable} day(s) available".($pending > 0 ? " after {$pending} pending" : '').", {$days} requested.");
                }
            }
        }

        return $count + ['balance' => $balanceInfo];
    }

    // ------------------------------------------------------------------ applicant actions

    public function submit(Employee $employee, LeaveType $type, array $data, ?UploadedFile $document, User $actor): LeaveRequest
    {
        [$start, $end] = [Carbon::parse($data['start_date']), Carbon::parse($data['end_date'] ?? $data['start_date'])];
        $count = $this->assess($employee, $type, $start, $end, (bool) ($data['half_day'] ?? false), $data['start_time'] ?? null, $data['end_time'] ?? null);
        $this->checkHandovers($employee, $data);
        if ($type->requires_document && ! $document) {
            throw ValidationException::withMessages(['document' => "A supporting document is required for {$type->name}."]);
        }

        $leave = DB::transaction(function () use ($employee, $type, $data, $start, $end, $count, $document) {
            $leave = new LeaveRequest($this->attributes($type, $data, $start, $end, $count));
            $leave->company_id = $employee->company_id;
            $leave->employee_id = $employee->id;
            $this->startChain($leave);
            $this->assignApprovers($leave, $employee);
            $leave->submitted_at = now();
            $this->route($leave);
            $leave->save();
            if ($document) {
                $this->storeDocument($leave, $document);
            }

            return $leave;
        });

        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['days' => $leave->days])->log('Leave applied');
        $this->notifyStage($leave->load('employee', 'leaveType'));

        return $leave;
    }

    public function update(LeaveRequest $leave, LeaveType $type, array $data, ?UploadedFile $document, User $actor): LeaveRequest
    {
        if (! in_array($leave->status, [...LeaveRequest::OPEN, LeaveRequest::HANDOVER_REJECTED], true)) {
            throw ValidationException::withMessages(['status' => 'Only pending requests can be edited.']);
        }
        $employee = $leave->employee;
        [$start, $end] = [Carbon::parse($data['start_date']), Carbon::parse($data['end_date'] ?? $data['start_date'])];
        $count = $this->assess($employee, $type, $start, $end, (bool) ($data['half_day'] ?? false), $data['start_time'] ?? null, $data['end_time'] ?? null, $leave);
        $this->checkHandovers($employee, $data);
        if ($type->requires_document && ! $document && ! $leave->document_path) {
            throw ValidationException::withMessages(['document' => "A supporting document is required for {$type->name}."]);
        }

        DB::transaction(function () use ($leave, $type, $data, $start, $end, $count, $document, $employee) {
            $leave->fill($this->attributes($type, $data, $start, $end, $count));
            // An edit goes back through the whole workflow: handover, then the approval chain from step one.
            $this->startChain($leave);
            $this->assignApprovers($leave, $employee);
            foreach ([1, 2] as $s) {
                $leave->{"handover{$s}_reason"} = null;
                $leave->{"handover{$s}_at"} = null;
            }
            $this->route($leave);
            $leave->save();
            if ($document) {
                $this->storeDocument($leave, $document);
            }
        });

        activity('leave')->performedOn($leave)->causedBy($actor)->log('Leave request edited');
        $leave->load('employee', 'leaveType');
        $this->send($this->supervisorRecipients($leave), $leave, 'edited');
        if ($leave->status === LeaveRequest::PENDING_HANDOVER) {
            $this->send($this->handoverRecipients($leave), $leave, 'handover_requested');
        }

        return $leave;
    }

    public function cancel(LeaveRequest $leave, User $actor, ?string $reason = null): LeaveRequest
    {
        if ($leave->status === LeaveRequest::APPROVED) {
            if (! $leave->start_date->isAfter(today())) {
                throw ValidationException::withMessages(['status' => 'Leave that has already started cannot be cancelled — ask your supervisor or HR to recall it.']);
            }
            DB::transaction(function () use ($leave, $actor, $reason) {
                $this->credit($leave, $leave->deducted_days, 'cancel', 'Approved leave cancelled'.($reason ? ": $reason" : ''), $actor);
                $leave->forceFill(['status' => LeaveRequest::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'deducted_days' => 0])->save();
            });
            $this->send($this->supervisorRecipients($leave->load('employee', 'leaveType')), $leave, 'cancelled', $reason);
            $this->attendanceChanged($leave, $leave->start_date, $leave->end_date, 'cancelled');
        } elseif (in_array($leave->status, [...LeaveRequest::OPEN, LeaveRequest::HANDOVER_REJECTED], true)) {
            $leave->forceFill(['status' => LeaveRequest::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
        } else {
            throw ValidationException::withMessages(['status' => 'This request can no longer be cancelled.']);
        }
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['reason' => $reason])->log('Leave cancelled');

        return $leave;
    }

    // ------------------------------------------------------------------ handover colleague

    public function handoverDecision(LeaveRequest $leave, User $actor, bool $approve, ?string $reason = null): LeaveRequest
    {
        $slot = $this->assertHandoverSlot($leave, $actor);
        $leave->{"handover{$slot}_status"} = $approve ? 'approved' : 'rejected';
        $leave->{"handover{$slot}_reason"} = $reason;
        $leave->{"handover{$slot}_at"} = now();
        if (! $approve) {
            $leave->status = LeaveRequest::HANDOVER_REJECTED;
        }
        $leave->save();
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['slot' => $slot, 'reason' => $reason])->log($approve ? 'Handover approved' : 'Handover rejected');

        $leave->load('employee.user', 'leaveType');
        $this->send([$leave->employee?->user], $leave, $approve ? 'handover_approved' : 'handover_rejected', $reason);
        if ($approve) {
            $this->advanceIfHandoverComplete($leave);
        }

        return $leave;
    }

    /** Handover colleague amends the dates (and accepts the handover for the new dates). */
    public function handoverAmend(LeaveRequest $leave, User $actor, array $data, ?string $reason = null): LeaveRequest
    {
        $slot = $this->assertHandoverSlot($leave, $actor);
        $this->applyAmendment($leave, $data, reservePending: true);
        $leave->{"handover{$slot}_status"} = 'approved';
        $leave->{"handover{$slot}_reason"} = $reason;
        $leave->{"handover{$slot}_at"} = now();
        $leave->save();
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['slot' => $slot, 'reason' => $reason])->log('Handover amended dates');

        $leave->load('employee.user', 'leaveType');
        $this->send([$leave->employee?->user], $leave, 'handover_amended', $reason);
        $this->advanceIfHandoverComplete($leave);

        return $leave;
    }

    // ------------------------------------------------------------------ supervisor

    public function approve(LeaveRequest $leave, User $actor, ?string $note = null, string $event = 'approved'): LeaveRequest
    {
        $this->assertCanDecide($leave, $actor);
        $type = $leave->leaveType;
        $employee = $leave->employee;

        $advanced = DB::transaction(function () use ($leave, $actor, $note, $type, $employee) {
            $leave = LeaveRequest::withoutGlobalScopes()->lockForUpdate()->findOrFail($leave->id);
            if ($leave->status !== LeaveRequest::PENDING_SUPERVISOR) {
                throw ValidationException::withMessages(['status' => 'This request has already been decided.']);
            }
            $this->recordStep($leave, $actor, 'approved', $note);
            $chain = $leave->approval_chain ?: null;
            if ($chain && $leave->approval_step !== null && $leave->approval_step < count($chain) - 1) {
                // Intermediate chain step: move on to the next approver; nothing is deducted yet.
                $leave->approval_step++;
                $this->assignApprovers($leave, $employee);
                $leave->save();

                return true;
            }
            if ($type->tracksBalance()) {
                $ledger = $type->ledgerType();
                $company = $employee->company ?? Company::findOrFail($employee->company_id);
                $portions = $this->yearPortions($type, $company, $leave->start_date, $leave->end_date, (bool) $leave->half_day, $leave->start_time, $leave->end_time, $leave->days);
                $balances = [];
                foreach ($portions as $year => $days) {
                    $balance = $this->balances->ensure($employee, $ledger, $year);
                    if (! $balance || $balance->available + 0.001 < $days) {
                        throw ValidationException::withMessages(['days' => "Insufficient {$ledger->name} balance".(count($portions) > 1 ? " for $year" : '').': '.($balance?->available ?? 0)." day(s) available, {$days} requested."]);
                    }
                    $balances[$year] = $balance;
                }
                foreach ($portions as $year => $days) {
                    $this->balances->post($balances[$year], 'deduction', -$days, "{$type->name} {$leave->start_date->toDateString()} – {$leave->end_date->toDateString()}".(count($portions) > 1 ? " ($year part)" : ''), $actor->id, $leave, "request:{$leave->id}", $year === $leave->start_date->year ? $leave->start_date : Carbon::create($year, 1, 1));
                }
                $leave->deducted_days = $leave->days;
                $leave->balance_year = $leave->start_date->year;
            }
            $leave->status = LeaveRequest::APPROVED;
            $leave->decided_by = $actor->id;
            $leave->decided_at = now();
            $leave->decision_note = $note;
            $leave->save();

            return false;
        });

        $leave->refresh()->load('employee.user', 'leaveType');
        if ($advanced) {
            $previous = $leave->approval_chain[$leave->approval_step - 1] ?? [];
            activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['step' => $leave->approval_step - 1, 'note' => $note])
                ->log('Leave approved at step: '.ApprovalChain::label($previous));
            $this->send([$leave->employee?->user], $leave, 'step_approved', ApprovalChain::label($previous));
            $this->send($this->supervisorRecipients($leave), $leave, 'supervisor_requested');

            return $leave;
        }
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['days' => $leave->days, 'note' => $note])->log($event === 'amended_approved' ? 'Leave amended and approved' : 'Leave approved');
        $this->send([$leave->employee?->user], $leave, $event, $note);
        $this->attendanceChanged($leave, $leave->start_date, $leave->end_date, 'approved');

        return $leave;
    }

    public function reject(LeaveRequest $leave, User $actor, string $reason): LeaveRequest
    {
        $this->assertCanDecide($leave, $actor);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'Give a reason for rejecting the request.']);
        }
        // A rejection at any chain step ends the request.
        $this->recordStep($leave, $actor, 'rejected', $reason);
        $leave->forceFill(['status' => LeaveRequest::REJECTED, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['reason' => $reason])->log('Leave rejected');
        $leave->load('employee.user', 'leaveType');
        $this->send([$leave->employee?->user], $leave, 'rejected', $reason);

        return $leave;
    }

    public function amendAndApprove(LeaveRequest $leave, User $actor, array $data, ?string $note = null): LeaveRequest
    {
        $this->assertCanDecide($leave, $actor);
        $this->applyAmendment($leave, $data, reservePending: false);
        $leave->save();

        return $this->approve($leave, $actor, $note, 'amended_approved');
    }

    // ------------------------------------------------------------------ recall

    public function recall(LeaveRequest $leave, User $actor, ?string $date = null, ?string $reason = null): LeaveRequest
    {
        abort_unless($this->canRecall($leave, $actor), 403, 'You cannot recall this leave.');
        if ($leave->status !== LeaveRequest::APPROVED || $leave->end_date->lt(today())) {
            throw ValidationException::withMessages(['status' => 'Only approved leave that has not ended can be recalled.']);
        }
        $recallDate = $date ? Carbon::parse($date) : today();
        if ($recallDate->gte($leave->end_date)) {
            throw ValidationException::withMessages(['recall_date' => 'The recall date must be before the leave end date ('.$leave->end_date->format('j M Y').').']);
        }

        $company = $leave->employee->company ?? Company::find($leave->company_id);
        $type = $leave->leaveType;
        // Days actually used, recounted with the same rule (working days for working-day types).
        $used = $recallDate->lt($leave->start_date) ? 0.0
            : $this->counter->forType($type, $company, $leave->start_date, $recallDate)['days'];
        $basis = $type->tracksBalance() ? $leave->deducted_days : $leave->days;
        $credit = max(0, round($basis - $used, 2));

        DB::transaction(function () use ($leave, $actor, $recallDate, $reason, $used, $credit, $type) {
            if ($type->tracksBalance() && $credit > 0) {
                $this->credit($leave, $credit, 'recall', 'Recalled from leave on '.$recallDate->toDateString().($reason ? ": $reason" : ''), $actor);
            }
            $leave->forceFill([
                'status' => LeaveRequest::RECALLED,
                'original_end_date' => $leave->original_end_date ?? $leave->end_date,
                'original_days' => $leave->original_days ?? $leave->days,
                'end_date' => $recallDate->lt($leave->start_date) ? $leave->end_date : $recallDate,
                'days' => $used,
                'deducted_days' => $type->tracksBalance() ? $used : 0,
                'recall_date' => $recallDate,
                'recalled_days' => $credit,
                'recalled_by' => $actor->id,
                'recalled_at' => now(),
                'recall_reason' => $reason,
            ])->save();
        });

        $reportOn = $this->counter->nextWorkingDay($recallDate->lt($leave->start_date) ? today()->subDay() : $recallDate, $company)->format('l j M Y');
        activity('leave')->performedOn($leave)->causedBy($actor)->withProperties(['recall_date' => $recallDate->toDateString(), 'credited' => $credit, 'reason' => $reason])->log('Leave recalled');
        $leave->load('employee.user', 'leaveType');
        $this->send([$leave->employee?->user], $leave, 'recalled', $reportOn);
        $this->attendanceChanged($leave, $leave->start_date, $leave->original_end_date ?? $leave->end_date, 'recalled');

        return $leave;
    }

    /** Lets Attendance recompute the affected days; failures are logged, never thrown. */
    private function attendanceChanged(LeaveRequest $leave, $from, $to, string $reason): void
    {
        if ($leave->employee_id && $from && $to) {
            SideEffects::dispatch(new LeaveDatesChanged((int) $leave->employee_id, Carbon::parse($from)->toDateString(), Carbon::parse($to)->toDateString(), $reason));
        }
    }

    // ------------------------------------------------------------------ permissions

    public function canDecide(LeaveRequest $leave, User $user): bool
    {
        if ($leave->status !== LeaveRequest::PENDING_SUPERVISOR) {
            return false;
        }
        $me = $user->employee;
        if ($me && (int) $me->id === (int) $leave->employee_id && ! $user->hasRole('super-admin')) {
            return false; // never approve your own leave
        }
        if ($me && $leave->supervisor_id && (int) $leave->supervisor_id === (int) $me->id) {
            return true; // built-in supervisor, or the resolved person of a supervisor / secondary / HOD chain step
        }
        $step = $this->currentStep($leave);
        if ($step && ($step['type'] ?? null) === 'role' && $leave->approver_role && $user->hasRole($leave->approver_role)) {
            return true;
        }

        return $user->can('leave.manage');
    }

    // ------------------------------------------------------------------ approval chain

    /** Snapshot the company's leave_request chain (null = built-in single-supervisor flow) and restart at step one. */
    private function startChain(LeaveRequest $leave): void
    {
        $steps = class_exists(ApprovalChain::class) ? ApprovalChain::steps((int) $leave->company_id, 'leave_request') : null;
        $leave->approval_chain = $steps;
        $leave->approval_step = $steps ? 0 : null;
        $leave->approval_history = null;
    }

    /** The chain step awaiting a decision, or null for the built-in flow. */
    public function currentStep(LeaveRequest $leave): ?array
    {
        $chain = $leave->approval_chain ?: null;

        return $chain && $leave->approval_step !== null ? ($chain[$leave->approval_step] ?? null) : null;
    }

    /** Append a decision to the request's approval history. */
    private function recordStep(LeaveRequest $leave, User $actor, string $decision, ?string $note): void
    {
        $step = $this->currentStep($leave);
        $history = $leave->approval_history ?? [];
        $history[] = [
            'step' => $leave->approval_step,
            'label' => $step ? ApprovalChain::label($step) : 'Supervisor',
            'decision' => $decision,
            'by' => $actor->id,
            'by_name' => $actor->name,
            'note' => $note,
            'at' => now()->toIso8601String(),
        ];
        $leave->approval_history = $history;
    }

    /** Employee id that decides the current stage (null for role steps, which approver_role covers). */
    private function stageApproverId(LeaveRequest $leave, ?Employee $employee): ?int
    {
        $step = $this->currentStep($leave);
        if (! $step) {
            return $this->resolver()->resolveId($employee?->primary_supervisor_id, [$leave->employee_id]);
        }
        $personId = match ($step['type'] ?? null) {
            'supervisor' => $employee?->primary_supervisor_id,
            'secondary_supervisor' => $employee?->secondary_supervisor_id,
            'hod' => $employee?->department?->head_employee_id,
            default => null,
        };

        // Same resolution as ApprovalChain::usersFor: escalate up the line when that person can't act.
        return $personId ? $this->resolver()->resolveId($personId, [$leave->employee_id]) : null;
    }

    public function canRecall(LeaveRequest $leave, User $user): bool
    {
        $me = $user->employee;
        if ($me && (int) $me->id === (int) $leave->employee_id && ! $user->hasRole('super-admin')) {
            return false;
        }
        if ($me && in_array((int) $me->id, [(int) $leave->supervisor_id, (int) $leave->employee?->primary_supervisor_id], true)) {
            return true;
        }

        return $user->can('leave.manage');
    }

    public function canView(LeaveRequest $leave, User $user): bool
    {
        $me = $user->employee;
        if ($me && ((int) $me->id === (int) $leave->employee_id || $leave->handoverSlotFor($me)
            || in_array((int) $me->id, [(int) $leave->handover1_approver_id, (int) $leave->handover2_approver_id], true)
            || (int) $leave->supervisor_id === (int) $me->id || (int) $leave->employee?->primary_supervisor_id === (int) $me->id)) {
            return true;
        }

        return $user->can('leave.manage') || $user->can('leave.reports') || $this->canDecide($leave, $user);
    }

    /**
     * Users who decide at the supervisor stage: the resolved approver (supervisor_id — the primary supervisor, or the
     * first reachable supervisor above them), else the company's leave administrators.
     */
    public function supervisorRecipients(LeaveRequest $leave): Collection
    {
        $applicantUserId = $leave->employee?->user_id ?? Employee::withoutGlobalScopes()->whereKey($leave->employee_id)->value('user_id');
        $step = $this->currentStep($leave);
        if ($step && ($step['type'] ?? null) === 'role') {
            $employee = $leave->employee ?? Employee::withoutGlobalScopes()->find($leave->employee_id);
            $users = $employee ? ApprovalChain::usersFor($employee, $step) : collect();

            return $users->isNotEmpty() ? $users : $this->leaveAdmins((int) $leave->company_id)->reject(fn ($u) => (int) $u->id === (int) $applicantUserId)->values();
        }

        return $this->resolver()->recipients($leave->supervisor_id, (int) $leave->company_id, 'leave.manage', [$applicantUserId]);
    }

    // ------------------------------------------------------------------ approver routing (ApproverResolver)

    private function resolver(): ApproverResolver
    {
        return app(ApproverResolver::class);
    }

    /** Store the resolved supervisor-stage approver and, per handover slot, the escalated answerer (if the colleague has no usable login). */
    public function assignApprovers(LeaveRequest $leave, ?Employee $employee = null): void
    {
        $employee ??= Employee::withoutGlobalScopes()->find($leave->employee_id);
        // Current stage: the built-in supervisor, or the person / role of the current chain step.
        $leave->supervisor_id = $this->stageApproverId($leave, $employee);
        $step = $this->currentStep($leave);
        $leave->approver_role = $step && ($step['type'] ?? null) === 'role' ? $step['role'] : null;
        foreach ([1, 2] as $s) {
            $leave->{"handover{$s}_approver_id"} = $this->handoverApproverFor($leave, $s);
        }
    }

    /** NULL when the colleague answers themselves (or nobody up their line can — leave admins then answer). */
    private function handoverApproverFor(LeaveRequest $leave, int $slot): ?int
    {
        $colleagueId = $leave->{"handover{$slot}_id"};
        if (! $colleagueId || $leave->{"handover{$slot}_status"} !== 'pending') {
            return null;
        }
        $colleague = Employee::withoutGlobalScopes()->with('user')->find($colleagueId);
        if (! $colleague || ApproverResolver::isReachable($colleague)) {
            return null;
        }

        return $this->resolver()->resolveId($colleague->primary_supervisor_id, [$leave->employee_id]);
    }

    /** Whether a handover slot has nobody reachable to answer it, so leave administrators may answer. */
    private function handoverNeedsAdmin(LeaveRequest $leave, int $slot): bool
    {
        $target = $leave->{"handover{$slot}_approver_id"} ?: $leave->{"handover{$slot}_id"};

        return ! ApproverResolver::isReachable($target ? Employee::withoutGlobalScopes()->with('user')->find($target) : null);
    }

    /**
     * Slot (1|2) the user may answer: as the colleague, as the escalated answerer, or as a leave administrator when
     * nobody reachable is assigned. Pending slots only for the latter two.
     */
    public function handoverSlotForUser(LeaveRequest $leave, User $user): ?int
    {
        $me = $user->employee;
        if ($slot = $leave->handoverSlotFor($me)) {
            return $slot;
        }
        if ($me && (int) $me->id === (int) $leave->employee_id) {
            return null;
        }
        foreach ([1, 2] as $s) {
            if (! $leave->{"handover{$s}_id"} || $leave->{"handover{$s}_status"} !== 'pending') {
                continue;
            }
            if ($me && (int) $leave->{"handover{$s}_approver_id"} === (int) $me->id) {
                return $s;
            }
        }
        if ($user->can('leave.manage')) {
            foreach ([1, 2] as $s) {
                if ($leave->{"handover{$s}_id"} && $leave->{"handover{$s}_status"} === 'pending' && $this->handoverNeedsAdmin($leave, $s)) {
                    return $s;
                }
            }
        }

        return null;
    }

    /** Pending handover requests the user has to answer (colleague, escalated answerer, or admin fallback). */
    public function handoverQueue(User $user): ?\Illuminate\Database\Eloquent\Builder
    {
        $me = $user->employee;
        $isAdmin = $user->can('leave.manage');
        if (! $me && ! $isAdmin) {
            return null;
        }

        return LeaveRequest::where('status', LeaveRequest::PENDING_HANDOVER)
            ->when($me, fn ($q) => $q->where('employee_id', '!=', $me->id))
            ->where(function ($w) use ($me, $isAdmin) {
                foreach ([1, 2] as $s) {
                    $w->orWhere(function ($slot) use ($s, $me, $isAdmin) {
                        $slot->whereNotNull("handover{$s}_id")->where("handover{$s}_status", 'pending')
                            ->where(function ($who) use ($s, $me, $isAdmin) {
                                if ($me) {
                                    $who->where("handover{$s}_id", $me->id)->orWhere("handover{$s}_approver_id", $me->id);
                                }
                                if ($isAdmin) {
                                    $who->orWhere(fn ($a) => $a->whereNull("handover{$s}_approver_id")
                                        ->where(fn ($x) => ApproverResolver::whereApproverUnreachable($x, "handover{$s}_id")))
                                        ->orWhere(fn ($a) => ApproverResolver::whereApproverUnreachable($a, "handover{$s}_approver_id"));
                                }
                            });
                    });
                }
            });
    }

    /**
     * Re-resolve the approvers of an open request (one-off / scheduled fix for items routed to people without a
     * usable login). Notifies the newly resolved approvers. Returns true when anything changed.
     */
    public function reroute(LeaveRequest $leave): bool
    {
        if (! in_array($leave->status, LeaveRequest::OPEN, true)) {
            return false;
        }
        $before = $leave->only(['supervisor_id', 'approver_role', 'handover1_approver_id', 'handover2_approver_id']);
        $this->assignApprovers($leave);
        $after = $leave->only(['supervisor_id', 'approver_role', 'handover1_approver_id', 'handover2_approver_id']);
        if ($before == $after) {
            return false;
        }
        $leave->saveQuietly();
        activity('leave')->performedOn($leave)->withProperties(['before' => $before, 'after' => $after])->log('Leave approvers re-routed');
        $leave->load('employee', 'leaveType');
        $this->notifyStage($leave);

        return true;
    }

    public function leaveAdmins(int $companyId): Collection
    {
        return User::where('company_id', $companyId)->where('is_active', true)->permission('leave.manage')->get();
    }

    // ------------------------------------------------------------------ internals

    private function attributes(LeaveType $type, array $data, Carbon $start, Carbon $end, array $count): array
    {
        $hourly = $type->allow_hours;
        $h1 = $data['handover1_id'] ?? null;
        $h2 = $data['handover2_id'] ?? null;
        if (! $h1 && $h2) {
            [$h1, $h2] = [$h2, null];
        }

        return [
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'half_day' => (bool) ($data['half_day'] ?? false),
            'start_time' => $hourly ? $data['start_time'] : null,
            'end_time' => $hourly ? $data['end_time'] : null,
            'hours' => $hourly ? $count['hours'] : null,
            'days' => $count['days'],
            'handover1_id' => $h1,
            'handover1_status' => $h1 ? 'pending' : null,
            'handover2_id' => $h2,
            'handover2_status' => $h2 ? 'pending' : null,
            'duties' => $data['duties'] ?? null,
            'comment' => $data['comment'] ?? null,
        ];
    }

    private function route(LeaveRequest $leave): void
    {
        $leave->status = $leave->hasHandover() ? LeaveRequest::PENDING_HANDOVER : LeaveRequest::PENDING_SUPERVISOR;
    }

    private function checkHandovers(Employee $employee, array $data): void
    {
        $ids = array_filter([$data['handover1_id'] ?? null, $data['handover2_id'] ?? null]);
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['handover2_id' => 'Choose two different handover colleagues.']);
        }
        foreach ($ids as $field => $id) {
            $colleague = Employee::withoutGlobalScopes()->find($id);
            $key = $field === 0 ? 'handover1_id' : 'handover2_id';
            if (! $colleague || (int) $colleague->company_id !== (int) $employee->company_id || $colleague->status !== 'active') {
                throw ValidationException::withMessages([$key => 'Choose an active colleague from your company.']);
            }
            if ((int) $colleague->id === (int) $employee->id) {
                throw ValidationException::withMessages([$key => 'You cannot hand over to yourself.']);
            }
        }
    }

    private function storeDocument(LeaveRequest $leave, UploadedFile $file): void
    {
        if ($leave->document_path) {
            Storage::disk('local')->delete($leave->document_path);
        }
        $path = $file->store("leave/documents/{$leave->company_id}", 'local');
        $leave->forceFill(['document_path' => $path, 'document_name' => mb_substr($file->getClientOriginalName(), 0, 250)])->save();
    }

    private function applyAmendment(LeaveRequest $leave, array $data, bool $reservePending): void
    {
        $type = $leave->leaveType;
        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date'] ?? $data['start_date']);
        $halfDay = (bool) ($data['half_day'] ?? false);
        $startTime = $data['start_time'] ?? $leave->start_time;
        $endTime = $data['end_time'] ?? $leave->end_time;
        $count = $this->assess($leave->employee, $type, $start, $end, $halfDay, $startTime, $endTime, $leave, checkBackdate: false, reservePending: $reservePending);

        $leave->original_start_date ??= $leave->start_date;
        $leave->original_end_date ??= $leave->end_date;
        $leave->original_days ??= $leave->days;
        $leave->fill([
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'half_day' => $halfDay,
            'start_time' => $type->allow_hours ? $startTime : null,
            'end_time' => $type->allow_hours ? $endTime : null,
            'hours' => $type->allow_hours ? $count['hours'] : null,
            'days' => $count['days'],
        ]);
        $leave->amended = true;
    }

    private function credit(LeaveRequest $leave, float $days, string $kind, string $reason, User $actor): void
    {
        if ($days <= 0 || ! $leave->leaveType->tracksBalance()) {
            return;
        }
        // Net days this request still holds per balance (deductions minus earlier credits), latest year first:
        // a recall cuts the end of the leave, so the later year's part is given back first.
        $held = LeaveTransaction::withoutGlobalScopes()->where('leave_request_id', $leave->id)
            ->with('balance')->get()->groupBy('leave_balance_id')
            ->map(fn ($txs) => ['balance' => $txs->first()->balance, 'days' => -round($txs->sum('days'), 2)])
            ->filter(fn ($row) => $row['balance'] && $row['days'] > 0)
            ->sortByDesc(fn ($row) => $row['balance']->year);
        if ($held->isEmpty()) {
            $balance = $this->balances->ensure($leave->employee, $leave->leaveType->ledgerType(), $leave->balance_year ?? $leave->start_date->year);
            if ($balance) {
                $this->balances->post($balance, 'recall_credit', $days, $reason, $actor->id, $leave, "$kind:{$leave->id}");
            }

            return;
        }
        $remaining = round($days, 2);
        foreach ($held as $row) {
            if ($remaining <= 0) {
                break;
            }
            $part = min($remaining, $row['days']);
            $this->balances->post($row['balance'], 'recall_credit', $part, $reason, $actor->id, $leave, "$kind:{$leave->id}");
            $remaining = round($remaining - $part, 2);
        }
    }

    /**
     * Days per balance year. Leave that spans 31 December is counted separately for each year so each part is
     * checked against and deducted from that year's balance.
     *
     * @return array<int, float> year => days
     */
    public function yearPortions(LeaveType $type, Company $company, Carbon $start, Carbon $end, bool $halfDay, ?string $startTime, ?string $endTime, float $total): array
    {
        if ($start->year === $end->year) {
            return [$start->year => $total];
        }
        $out = [];
        for ($y = $start->year; $y <= $end->year; $y++) {
            $from = $y === $start->year ? $start->copy() : Carbon::create($y, 1, 1);
            $to = $y === $end->year ? $end->copy() : Carbon::create($y, 12, 31);
            $days = $this->counter->forType($type, $company, $from, $to, $halfDay, $startTime, $endTime)['days'];
            if ($days > 0) {
                $out[$y] = (float) $days;
            }
        }

        return $out ?: [$start->year => $total];
    }

    private function assertHandoverSlot(LeaveRequest $leave, User $actor): int
    {
        $slot = $this->handoverSlotForUser($leave, $actor);
        abort_unless($slot, 403, 'You are not a handover colleague on this request.');
        if ($leave->status !== LeaveRequest::PENDING_HANDOVER || $leave->{"handover{$slot}_status"} !== 'pending') {
            throw ValidationException::withMessages(['status' => 'This handover request has already been answered.']);
        }

        return $slot;
    }

    private function assertCanDecide(LeaveRequest $leave, User $actor): void
    {
        if ($leave->status !== LeaveRequest::PENDING_SUPERVISOR) {
            throw ValidationException::withMessages(['status' => 'This request is not awaiting a supervisor decision.']);
        }
        abort_unless($this->canDecide($leave, $actor), 403, 'You are not the approver for this request.');
    }

    private function advanceIfHandoverComplete(LeaveRequest $leave): void
    {
        $pending = collect([1, 2])->filter(fn ($s) => $leave->{"handover{$s}_id"} && $leave->{"handover{$s}_status"} !== 'approved');
        if ($leave->status === LeaveRequest::PENDING_HANDOVER && $pending->isEmpty()) {
            // Re-resolve now: the supervisor may have lost their login since the request was filed.
            $leave->status = LeaveRequest::PENDING_SUPERVISOR;
            $this->assignApprovers($leave);
            $leave->save();
            $this->send($this->supervisorRecipients($leave), $leave, 'supervisor_requested');
        }
    }

    private function handoverRecipients(LeaveRequest $leave): array
    {
        $applicantUserId = $leave->employee?->user_id;

        return collect([1, 2])
            ->filter(fn ($s) => $leave->{"handover{$s}_id"} && $leave->{"handover{$s}_status"} === 'pending')
            ->flatMap(fn ($s) => $this->resolver()->recipients(
                $leave->{"handover{$s}_approver_id"} ?: $leave->{"handover{$s}_id"}, (int) $leave->company_id, 'leave.manage', [$applicantUserId]
            ))->all();
    }

    private function notifyStage(LeaveRequest $leave): void
    {
        $leave->status === LeaveRequest::PENDING_HANDOVER
            ? $this->send($this->handoverRecipients($leave), $leave, 'handover_requested')
            : $this->send($this->supervisorRecipients($leave), $leave, 'supervisor_requested');
    }

    /** Notify users, skipping employees without an account. Failures never break the workflow. */
    public function send(iterable $users, LeaveRequest $leave, string $event, ?string $note = null): void
    {
        $users = collect($users)->filter()->unique('id');
        if ($users->isEmpty()) {
            return;
        }
        try {
            Notification::send($users, new LeaveRequestNotification($leave, $event, $note));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
