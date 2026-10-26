<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveRequest extends Model
{
    use BelongsToCompany, LogsActivity;

    public const PENDING_HANDOVER = 'pending_handover';
    public const PENDING_SUPERVISOR = 'pending_supervisor';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';
    public const HANDOVER_REJECTED = 'handover_rejected';
    public const CANCELLED = 'cancelled';
    public const RECALLED = 'recalled';

    public const STATUSES = [
        self::PENDING_HANDOVER => 'Pending handover',
        self::PENDING_SUPERVISOR => 'Pending supervisor',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::HANDOVER_REJECTED => 'Handover rejected',
        self::CANCELLED => 'Cancelled',
        self::RECALLED => 'Recalled',
    ];

    public const OPEN = [self::PENDING_HANDOVER, self::PENDING_SUPERVISOR];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'original_start_date' => 'date',
            'original_end_date' => 'date',
            'recall_date' => 'date',
            'half_day' => 'boolean',
            'amended' => 'boolean',
            'hours' => 'float',
            'days' => 'float',
            'original_days' => 'float',
            'deducted_days' => 'float',
            'recalled_days' => 'float',
            'handover1_at' => 'datetime',
            'handover2_at' => 'datetime',
            'decided_at' => 'datetime',
            'recalled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reminded_at' => 'datetime',
            'approval_step' => 'integer',
            'approval_chain' => 'array',
            'approval_history' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'start_date', 'end_date', 'days', 'handover1_status', 'handover2_status', 'decision_note', 'recall_date'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('leave');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function handover1(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handover1_id');
    }

    public function handover2(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handover2_id');
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function recaller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recalled_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class);
    }

    /** Requests that occupy days on the calendar (approved, or recalled with days actually taken). */
    public function scopeTaken(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('status', self::APPROVED)
            ->orWhere(fn ($r) => $r->where('status', self::RECALLED)->where('days', '>', 0)));
    }

    /** Requests that block the dates for a new application. */
    public function scopeBlocking(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereIn('status', [...self::OPEN, self::APPROVED])
            ->orWhere(fn ($r) => $r->where('status', self::RECALLED)->where('days', '>', 0)));
    }

    public function scopeOverlapping(Builder $q, $from, $to): Builder
    {
        return $q->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from);
    }

    /** 1 or 2 when the employee is a handover colleague on this request. */
    public function handoverSlotFor(?Employee $employee): ?int
    {
        if (! $employee) {
            return null;
        }
        foreach ([1, 2] as $slot) {
            if ((int) $this->{"handover{$slot}_id"} === $employee->id) {
                return $slot;
            }
        }

        return null;
    }

    public function hasHandover(): bool
    {
        return (bool) ($this->handover1_id || $this->handover2_id);
    }

    public function documentUrl(): ?string
    {
        return $this->document_path ? "/api/leave/requests/{$this->id}/document" : null;
    }

    /** API representation. */
    /** "Pending supervisor" only when a supervisor actually holds it; otherwise name who does (chain step or HR). */
    public function statusLabel(): string
    {
        if ($this->status === self::PENDING_SUPERVISOR) {
            $step = $this->approval_chain[$this->approval_step ?? 0] ?? null;
            if ($step && ($step['type'] ?? null) !== 'supervisor') {
                return 'Pending '.\App\Modules\Organization\Support\ApprovalChain::label($step);
            }
            // No supervisor stage at all (no supervisor, or none who can act): HR is the only approver.
            if (! $this->supervisor_id && ! $this->approver_role) {
                return 'Pending HR approval (no supervisor)';
            }
        }

        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function present(): array
    {
        $person = fn (?Employee $e) => $e ? ['id' => $e->id, 'name' => $e->full_name, 'photo_url' => $e->photo_url] : null;

        return [
            'id' => $this->id,
            'employee' => $this->relationLoaded('employee') && $this->employee ? [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name,
                'employee_number' => $this->employee->employee_number,
                'photo_url' => $this->employee->photo_url,
                'department' => $this->employee->relationLoaded('department') ? $this->employee->department?->name : null,
                'branch' => $this->employee->relationLoaded('branch') ? $this->employee->branch?->name : null,
            ] : null,
            'leave_type' => $this->relationLoaded('leaveType') && $this->leaveType ? [
                'id' => $this->leaveType->id, 'name' => $this->leaveType->name, 'code' => $this->leaveType->code,
                'color' => $this->leaveType->color, 'paid' => $this->leaveType->paid,
            ] : null,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'half_day' => $this->half_day,
            'start_time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr($this->end_time, 0, 5) : null,
            'hours' => $this->hours,
            'days' => $this->days,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'handover1' => $this->handover1_id ? ['employee' => $person($this->handover1), 'status' => $this->handover1_status, 'reason' => $this->handover1_reason, 'at' => $this->handover1_at] : null,
            'handover2' => $this->handover2_id ? ['employee' => $person($this->handover2), 'status' => $this->handover2_status, 'reason' => $this->handover2_reason, 'at' => $this->handover2_at] : null,
            'duties' => $this->duties,
            'comment' => $this->comment,
            'document_url' => $this->documentUrl(),
            'document_name' => $this->document_name,
            'supervisor' => $person($this->supervisor),
            'decided_by' => $this->decider?->name,
            'decided_at' => $this->decided_at,
            'decision_note' => $this->decision_note,
            'amended' => $this->amended,
            'original_start_date' => $this->original_start_date?->toDateString(),
            'original_end_date' => $this->original_end_date?->toDateString(),
            'original_days' => $this->original_days,
            'deducted_days' => $this->deducted_days,
            'recall_date' => $this->recall_date?->toDateString(),
            'recalled_days' => $this->recalled_days,
            'recall_reason' => $this->recall_reason,
            'recalled_by' => $this->recaller?->name,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            // Configurable approval chain: every step with its state (null = built-in single-supervisor flow).
            'approval_steps' => $this->approval_chain ? collect($this->approval_chain)->map(fn ($step, $i) => [
                'label' => \App\Modules\Organization\Support\ApprovalChain::label($step),
                'state' => match (true) {
                    $this->status === self::PENDING_SUPERVISOR && $i === $this->approval_step => 'current',
                    collect($this->approval_history ?? [])->contains(fn ($h) => $h['step'] === $i && $h['decision'] === 'approved') => 'approved',
                    collect($this->approval_history ?? [])->contains(fn ($h) => $h['step'] === $i && $h['decision'] === 'rejected') => 'rejected',
                    default => 'waiting',
                },
            ])->values() : null,
            'approval_step' => $this->approval_step,
            'approval_history' => $this->approval_history ?? [],
            'can_edit' => in_array($this->status, [...self::OPEN, self::HANDOVER_REJECTED], true),
            'can_cancel' => in_array($this->status, [...self::OPEN, self::HANDOVER_REJECTED], true)
                || ($this->status === self::APPROVED && $this->start_date?->isAfter(today())),
            'submitted_at' => $this->submitted_at ?? $this->created_at,
            'created_at' => $this->created_at,
        ];
    }

    public const PRESENT_WITH = ['employee.department:id,name', 'employee.branch:id,name', 'leaveType', 'handover1', 'handover2', 'supervisor', 'decider:id,name', 'recaller:id,name'];
}
