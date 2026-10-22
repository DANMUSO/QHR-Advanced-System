<?php

namespace App\Modules\Benefits\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** An employee's membership of a pension scheme or SACCO. */
class PensionMember extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'benefit_pension_members';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'employee_contribution' => 'decimal:2',
            'employer_contribution' => 'decimal:2',
            'total_balance' => 'decimal:2',
            'joined_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('benefits')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
