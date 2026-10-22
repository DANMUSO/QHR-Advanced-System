<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** A resignation submitted from My Portal. HR converts it into an exit through the normal exit flow. */
class ResignationRequest extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'employee_resignations';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['letter_path'];

    protected function casts(): array
    {
        return ['notice_date' => 'date', 'last_working_day' => 'date', 'decided_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('employees')->logOnly(['status', 'last_working_day', 'decision_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function exitRecord(): BelongsTo
    {
        return $this->belongsTo(ExitRecord::class, 'exit_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
