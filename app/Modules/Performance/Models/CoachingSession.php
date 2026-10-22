<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A GROW coaching conversation (Goal, Reality, Options, Way forward). */
class CoachingSession extends Model
{
    use BelongsToCompany, LogsActivity;

    public const OUTCOMES = ['completed', 'extended', 'escalated'];

    protected $table = 'perf_coaching_sessions';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['session_date' => 'date:Y-m-d', 'next_meeting_date' => 'date:Y-m-d'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function pip(): BelongsTo
    {
        return $this->belongsTo(Pip::class);
    }
}
