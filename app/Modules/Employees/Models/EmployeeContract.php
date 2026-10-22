<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeContract extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    public const TYPES = [
        'permanent' => 'Permanent', 'fixed_term' => 'Fixed term', 'casual' => 'Casual',
        'probation' => 'Probationary', 'internship' => 'Internship',
    ];

    public const STATUSES = ['draft', 'active', 'expired', 'terminated', 'superseded'];

    /** Contract type to employees.contract_type. */
    public const EMPLOYEE_TYPE = [
        'permanent' => 'permanent', 'fixed_term' => 'contract', 'casual' => 'casual', 'internship' => 'intern', 'probation' => 'permanent',
    ];

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'gross_salary' => 'decimal:2'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('employees')->logAll()->logExcept(['updated_at', 'file_path'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
