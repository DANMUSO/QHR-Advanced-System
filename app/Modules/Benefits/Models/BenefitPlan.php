<?php

namespace App\Modules\Benefits\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Benefits Administration view of the shared `benefits` catalogue (adds type/value/eligibility/status + audit). */
class BenefitPlan extends Model
{
    use BelongsToCompany, LogsActivity;

    public const TYPES = ['allowance', 'insurance', 'pension', 'leave', 'other'];

    public const ELIGIBILITY = ['all', 'permanent', 'management', 'confirmed'];

    protected $table = 'benefits';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('benefits')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'benefit_employee', 'benefit_id', 'employee_id');
    }
}
