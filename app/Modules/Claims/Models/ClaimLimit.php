<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ClaimLimit extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['annual_limit' => 'decimal:2', 'monthly_limit' => 'decimal:2'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['annual_limit', 'monthly_limit', 'notes'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('claims');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
