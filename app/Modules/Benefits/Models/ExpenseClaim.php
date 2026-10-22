<?php

namespace App\Modules\Benefits\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class ExpenseClaim extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    public const CATEGORIES = ['travel', 'accommodation', 'meals', 'transport', 'fuel', 'airtime', 'communication', 'stationery', 'training', 'client_entertainment', 'other'];

    /** submitted → approved | rejected; approved → paid, or payroll_pending → paid when the payroll run is approved. */
    public const STATUSES = ['submitted', 'approved', 'rejected', 'payroll_pending', 'paid'];

    public const PAYMENT_METHODS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'payroll' => 'Through payroll'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date', 'decided_at' => 'datetime', 'paid_at' => 'datetime', 'payroll_queued_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('expenses')->logOnly(['description', 'amount', 'status', 'decision_note', 'payment_method', 'payment_reference'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }
}
