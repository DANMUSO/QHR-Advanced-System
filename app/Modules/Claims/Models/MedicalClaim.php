<?php

namespace App\Modules\Claims\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\EmployeeDependant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class MedicalClaim extends Model
{
    use BelongsToCompany, LogsActivity;

    public const SUBMITTED = 'submitted';
    public const HR_APPROVED = 'hr_approved';
    public const HR_REJECTED = 'hr_rejected';
    public const PAID = 'paid';
    public const FINANCE_REJECTED = 'finance_rejected';
    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::SUBMITTED => 'Pending HR approval',
        self::HR_APPROVED => 'Awaiting payment',
        self::HR_REJECTED => 'Rejected by HR',
        self::PAID => 'Paid',
        self::FINANCE_REJECTED => 'Rejected by Finance',
        self::CANCELLED => 'Cancelled',
    ];

    /** Statuses that consume the balance (approved and not finance-rejected). */
    public const APPROVED_STATUSES = [self::HR_APPROVED, self::PAID];

    public const CATEGORIES = [
        'consultation' => 'Consultation',
        'pharmacy' => 'Pharmacy',
        'laboratory' => 'Laboratory',
        'radiology' => 'Radiology',
        'dental' => 'Dental',
        'optical' => 'Optical',
        'other' => 'Other',
    ];

    public const CLAIMANT_TYPES = ['self' => 'Self', 'spouse' => 'Spouse', 'child' => 'Child', 'parent' => 'Parent', 'other' => 'Other dependant'];

    public const PAYMENT_METHODS = ['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash'];

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'claim_date' => 'date:Y-m-d',
            'paid_on' => 'date:Y-m-d',
            'total_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'hr_decided_at' => 'datetime',
            'finance_decided_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('claims')
            ->logOnly(['status', 'total_amount', 'approved_amount', 'claim_date', 'payment_method', 'payment_reference'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company')->withTrashed();
    }

    public function dependant(): BelongsTo
    {
        return $this->belongsTo(EmployeeDependant::class, 'dependant_id');
    }

    public function routedTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'routed_to_employee_id')->withoutGlobalScope('company');
    }

    public function hrDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_decided_by');
    }

    public function financeDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finance_decided_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MedicalClaimItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(MedicalClaimReceipt::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(MedicalClaimEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeApproved(Builder $q): Builder
    {
        return $q->whereIn('status', self::APPROVED_STATUSES);
    }

    public function scopeInYear(Builder $q, int $year): Builder
    {
        return $q->whereBetween('claim_date', ["$year-01-01", "$year-12-31"]);
    }

    public function isPending(): bool
    {
        return $this->status === self::SUBMITTED;
    }

    /** Amount that counts against the limit (HR may approve less than claimed). */
    public function effectiveAmount(): float
    {
        return (float) ($this->approved_amount ?? $this->total_amount);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
