<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ClaimApprover extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['medical_approver_id', 'finance_approver_id'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('claims');
    }

    public function medicalApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'medical_approver_id')->withoutGlobalScope('company');
    }

    public function financeApprover(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'finance_approver_id')->withoutGlobalScope('company');
    }

    public static function forCompany(int $companyId): ?self
    {
        return static::withoutGlobalScope('company')->where('company_id', $companyId)->first();
    }
}
