<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Appraisal extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'perf_appraisals';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'kpi_score' => 'float', 'competency_score' => 'float', 'final_score' => 'float',
            'kpi_weight' => 'float', 'competency_weight' => 'float',
            'finalized_at' => 'datetime', 'acknowledged_at' => 'datetime', 'calibrated_at' => 'datetime',
        ];
    }

    public const STATUS_LABELS = [
        'pending' => 'Self-review not started',
        'self_review' => 'Self-review in progress',
        'manager_review' => 'Awaiting supervisor review',
        'completed' => 'Completed',
    ];

    /** Rating after calibration, falling back to the supervisor's band. */
    public function effectiveBand(): ?string
    {
        return $this->calibrated_band ?? $this->rating_band;
    }

    public function questionnaire(): BelongsTo
    {
        return $this->belongsTo(Questionnaire::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }
}
