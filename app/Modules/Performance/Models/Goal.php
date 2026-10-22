<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** One employee's copy of a KPI, with its own target, lifecycle status and score. */
class Goal extends Model
{
    use BelongsToCompany, LogsActivity;

    public const STATUSES = ['cascaded', 'in_progress', 'under_review', 'submitted', 'approved'];

    /** Statuses in which the employee still has work to do. */
    public const OPEN = ['cascaded', 'in_progress', 'under_review'];

    protected $table = 'perf_goals';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['evidence_path'];

    protected $appends = ['has_evidence'];

    protected function casts(): array
    {
        return [
            'target' => 'float', 'achieved' => 'float', 'achievement_pct' => 'float', 'score' => 'float',
            'due_date' => 'date:Y-m-d', 'accepted_at' => 'datetime', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'target', 'achieved', 'score', 'due_date', 'approver_id'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function getHasEvidenceAttribute(): bool
    {
        return (bool) $this->evidence_path;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', self::OPEN);
    }

    /** Filter by year / quarter / month of the due date. */
    public function scopePeriod(Builder $q, ?int $year, ?int $quarter = null, ?int $month = null): Builder
    {
        return $q->when($year, fn ($q) => $q->whereYear('due_date', $year))
            ->when($quarter, fn ($q) => $q->whereMonth('due_date', '>=', ($quarter - 1) * 3 + 1)->whereMonth('due_date', '<=', $quarter * 3))
            ->when($month, fn ($q) => $q->whereMonth('due_date', $month));
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(Kpi::class);
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approver_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_goal_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_goal_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(GoalEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function latestEvent(): HasOne
    {
        return $this->hasOne(GoalEvent::class)->latestOfMany('created_at');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(GoalRating::class);
    }
}
