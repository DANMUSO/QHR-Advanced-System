<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Kpi extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'perf_kpis';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'target' => 'float', 'is_recurring' => 'boolean',
            'due_date' => 'date:Y-m-d', 'period_start' => 'date:Y-m-d', 'rolled_over_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(Objective::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function frequency(): BelongsTo
    {
        return $this->belongsTo(Frequency::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'perf_kpi_questions', 'kpi_id', 'question_id');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    /** Percentage KPIs (by type or unit) roll up by average and are exempt from the distribution cap. */
    public function isPercentage(): bool
    {
        return $this->kpi_type === 'percentage' || (bool) $this->unit?->is_percentage;
    }

    public function isQualitative(): bool
    {
        return $this->type === 'qualitative';
    }
}
