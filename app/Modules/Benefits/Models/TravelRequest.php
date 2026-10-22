<?php

namespace App\Modules\Benefits\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TravelRequest extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['estimated_cost' => 'decimal:2', 'departure_date' => 'date', 'return_date' => 'date', 'decided_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('expenses')->logOnly(['destination', 'estimated_cost', 'status', 'decision_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
