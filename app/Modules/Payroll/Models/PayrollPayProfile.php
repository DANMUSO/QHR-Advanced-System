<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollPayProfile extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'disability_exempt' => 'boolean', 'on_hold' => 'boolean', 'basic_salary' => 'float',
            'pension_employee_percent' => 'float', 'pension_employer_percent' => 'float',
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

    public function structure(): BelongsTo
    {
        return $this->belongsTo(PayrollStructure::class, 'structure_id');
    }

    public function paymentDetails(): array
    {
        return match ($this->payment_method) {
            'bank' => ['bank_name' => $this->bank_name, 'bank_code' => $this->bank_code, 'bank_branch' => $this->bank_branch,
                'branch_code' => $this->branch_code, 'account_name' => $this->account_name, 'account_number' => $this->account_number],
            'mpesa' => ['mpesa_phone' => $this->mpesa_phone],
            default => [],
        };
    }

    public function hasPaymentDetails(): bool
    {
        return match ($this->payment_method) {
            'bank' => filled($this->bank_name) && filled($this->account_number),
            'mpesa' => filled($this->mpesa_phone),
            default => true,
        };
    }

    public const PAYMENT_METHODS = ['bank' => 'Bank transfer', 'mpesa' => 'M-Pesa', 'cash' => 'Cash'];

    /** Ready to be paid: a basic salary above zero and complete payment details for the chosen method. */
    public function isPaymentReady(): bool
    {
        return (float) $this->basic_salary > 0 && $this->hasPaymentDetails();
    }

    /** Why the profile is not payment ready (empty when it is). */
    public function readinessIssues(): array
    {
        $issues = [];
        if ((float) $this->basic_salary <= 0) {
            $issues[] = 'Basic salary is not set.';
        }
        if (! $this->hasPaymentDetails()) {
            $issues[] = 'Payment details are incomplete for '.(self::PAYMENT_METHODS[$this->payment_method] ?? $this->payment_method).'.';
        }

        return $issues;
    }
}
