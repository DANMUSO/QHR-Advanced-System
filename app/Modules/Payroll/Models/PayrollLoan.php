<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollLoan extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_period' => 'date:Y-m-d', 'settled_at' => 'datetime',
            'principal' => 'float', 'interest_rate' => 'float', 'total_interest' => 'float', 'total_repayable' => 'float',
            'amount_repaid' => 'float', 'balance' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class)->withoutGlobalScope('company');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(PayrollLoanInstalment::class)->orderBy('number');
    }

    /** Outstanding = sum of instalments not yet deducted. */
    public function remainingRepayments(): float
    {
        return round((float) $this->schedule()->where('status', 'pending')->sum('amount'), 2);
    }
}
