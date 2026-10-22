<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class TalentPool extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'succ_talent_pools';

    protected $guarded = ['id', 'company_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'succ_talent_pool_members', 'talent_pool_id', 'employee_id')->withTimestamps();
    }
}
