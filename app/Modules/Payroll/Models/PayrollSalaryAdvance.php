<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollSalaryAdvance extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'amount_recovered' => 'float', 'repayment_months' => 'integer', 'decided_at' => 'datetime', 'recovery_period' => 'date:Y-m-d'];
    }

    /** Amount still to be recovered through payroll. */
    public function remaining(): float
    {
        return max(0.0, round((float) $this->amount - (float) $this->amount_recovered, 2));
    }

    /** This month's recovery: an equal share of the advance; the last instalment takes whatever is left. */
    public function nextInstalment(): float
    {
        $months = max(1, (int) ($this->repayment_months ?: 1));
        $remaining = $this->remaining();
        $share = round((float) $this->amount / $months, 2);

        // Avoid leaving a few cents behind for an extra month.
        return ($remaining - $share) < 1 ? $remaining : $share;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
