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

class PayrollRun extends Model
{
    use BelongsToCompany, LogsActivity;

    public const STATUSES = ['draft', 'calculated', 'reviewed', 'approved', 'paid'];

    public const OFF_CYCLE_REASONS = ['bonus' => 'Bonus', 'arrears' => 'Arrears', 'correction' => 'Correction', 'final_dues' => 'Final dues'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'date:Y-m-d', 'period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'pay_date' => 'date:Y-m-d',
            'needs_recalculation' => 'boolean', 'options' => 'array',
            'calculated_at' => 'datetime', 'reviewed_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime',
            'payslips_emailed_at' => 'datetime',
            'total_gross' => 'float', 'total_deductions' => 'float', 'total_net' => 'float', 'total_paye' => 'float', 'total_employer_cost' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')
            ->logOnly(['title', 'status', 'pay_date', 'total_gross', 'total_net', 'notes', 'approved_by', 'reviewed_by'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function isLocked(): bool
    {
        return in_array($this->status, ['approved', 'paid'], true);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'calculated', 'reviewed'], true);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PayrollRunEmployee::class);
    }

    public function employees(): HasMany
    {
        return $this->lines();
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function exitEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'exit_employee_id')->withoutGlobalScope('company');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function calculator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calculated_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
