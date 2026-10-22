<?php

namespace App\Modules\Compliance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Base for company-owned compliance records: tenant scoping + audit trail (log name "compliance"). */
abstract class ComplianceRecord extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id', 'company_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('compliance')->logAll()->logExcept(['updated_at', 'author_hash'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_user_id');
    }
}
