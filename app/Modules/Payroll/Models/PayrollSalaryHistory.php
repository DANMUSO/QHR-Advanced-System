<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollSalaryHistory extends Model
{
    use BelongsToCompany, LogsActivity;

    public const REASONS = ['initial' => 'Initial', 'promotion' => 'Promotion', 'increment' => 'Increment', 'adjustment' => 'Adjustment',
        'transfer' => 'Company transfer', 'reinstatement' => 'Reinstatement'];

    protected $table = 'payroll_salary_history';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'basic_salary' => 'float', 'previous_salary' => 'float'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
