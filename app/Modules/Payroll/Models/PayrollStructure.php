<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\EmploymentLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollStructure extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['components' => 'array', 'active' => 'boolean', 'basic_salary' => 'float', 'min_salary' => 'float', 'max_salary' => 'float'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(EmploymentLevel::class, 'employment_level_id');
    }

    public function profiles(): HasMany
    {
        return $this->hasMany(PayrollPayProfile::class, 'structure_id');
    }
}
