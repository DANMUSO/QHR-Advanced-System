<?php

namespace App\Modules\Employees\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A self-service change to sensitive profile data (statutory numbers, next of kin, pay details) awaiting HR approval.
 * `changes` holds only what the employee changed; `previous` the values on file at the time.
 */
class ProfileChangeRequest extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'employee_profile_change_requests';

    protected $guarded = ['id', 'company_id'];

    /** Fields that change only through an approved request. */
    public const STATUTORY = ['national_id', 'kra_pin', 'nssf_number', 'shif_number', 'helb_number'];

    public const PAYMENT = ['payment_method', 'bank_name', 'bank_code', 'bank_branch', 'branch_code', 'account_name', 'account_number', 'mpesa_phone'];

    public const LABELS = [
        'national_id' => 'National ID', 'kra_pin' => 'KRA PIN', 'nssf_number' => 'NSSF No.', 'shif_number' => 'SHIF/NHIF No.',
        'helb_number' => 'HELB No.', 'next_of_kin' => 'Next of kin', 'payment' => 'Pay details',
    ];

    protected function casts(): array
    {
        return ['changes' => 'array', 'previous' => 'array', 'decided_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('employees')->logOnly(['status', 'decision_note'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    // Note: `changes` clashes with Eloquent's internal $changes property inside this class, so read it via getAttribute().
    /** Human list of what is being changed, e.g. "KRA PIN, Pay details". */
    public function summary(): string
    {
        return collect(array_keys($this->getAttribute('changes') ?? []))->map(fn ($k) => self::LABELS[$k] ?? $k)->implode(', ');
    }
}
