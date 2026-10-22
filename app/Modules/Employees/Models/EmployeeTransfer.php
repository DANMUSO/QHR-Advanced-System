<?php

namespace App\Modules\Employees\Models;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeTransfer extends Model
{
    use BelongsToCompany, LogsActivity;

    public const TYPES = [
        'transfer' => 'Transfer', 'promotion' => 'Promotion', 'demotion' => 'Demotion',
        'redeployment' => 'Redeployment', 'company_transfer' => 'Company transfer',
    ];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'applied_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('employees')
            ->logOnly(['type', 'status', 'effective_date', 'to_department_id', 'to_position_id', 'to_branch_id'])->logOnlyDirty();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    private function unscoped(string $class, string $fk): BelongsTo
    {
        return $this->belongsTo($class, $fk)->withoutGlobalScope('company');
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->unscoped(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->unscoped(Department::class, 'to_department_id');
    }

    public function fromPosition(): BelongsTo
    {
        return $this->unscoped(Position::class, 'from_position_id');
    }

    public function toPosition(): BelongsTo
    {
        return $this->unscoped(Position::class, 'to_position_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->unscoped(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->unscoped(Branch::class, 'to_branch_id');
    }

    public function toLevel(): BelongsTo
    {
        return $this->unscoped(EmploymentLevel::class, 'to_level_id');
    }

    public function toSupervisor(): BelongsTo
    {
        return $this->unscoped(Employee::class, 'to_supervisor_id');
    }

    public function fromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'from_company_id');
    }

    public function toCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'to_company_id');
    }
}
