<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Base for recruitment records: company-scoped and audit-logged. */
abstract class RecruitmentModel extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id', 'company_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('recruitment')->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }
}
