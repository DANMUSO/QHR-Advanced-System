<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Plan extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'perf_plans';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /** The user who created the plan (set even when they have no employee record). */
    public function creatorUser(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by_user_id');
    }

    public function focusAreas(): HasMany
    {
        return $this->hasMany(FocusArea::class)->orderBy('sort')->orderBy('id');
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(Objective::class);
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(Kpi::class);
    }

    public function totalWeight(): float
    {
        return round((float) $this->objectives()->sum('weight'), 2);
    }
}
