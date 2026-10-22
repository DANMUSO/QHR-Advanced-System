<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** One-off earning / deduction for an employee in a run. Child of PayrollRun (loaded through the run). */
class PayrollAdjustment extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id')->withoutGlobalScope('company');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class)->withoutGlobalScope('company');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
