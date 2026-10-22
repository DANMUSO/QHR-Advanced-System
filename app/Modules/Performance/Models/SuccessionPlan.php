<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class SuccessionPlan extends Model
{
    use BelongsToCompany, LogsActivity;

    public const READINESS = ['ready_now', 'ready_1_2_years', 'ready_3_5_years', 'not_ready'];

    public const RISK = ['low', 'medium', 'high'];

    protected $table = 'succ_plans';

    protected $guarded = ['id', 'company_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function incumbent(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'incumbent_id');
    }

    public function successor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'successor_id');
    }
}
