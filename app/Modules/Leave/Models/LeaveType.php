<?php

namespace App\Modules\Leave\Models;

use App\Models\Company;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class LeaveType extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id', 'company_id'];

    /** Default catalogue seeded for every company (HR-FEATURES §3.1 / §3.6). */
    public const DEFAULTS = [
        ['code' => 'ANNUAL', 'name' => 'Annual Leave', 'days_per_year' => 21, 'opening_days' => 1.75, 'monthly_accrual' => 1.75, 'counting' => 'working', 'accrues_monthly' => true, 'carry_forward' => true, 'allow_half_day' => true, 'color' => '#6366f1', 'sort_order' => 10],
        ['code' => 'SICK_FULL', 'name' => 'Sick Leave (Full Pay)', 'days_per_year' => 15, 'opening_days' => 7, 'counting' => 'working', 'requires_document' => true, 'allow_half_day' => true, 'color' => '#f43f5e', 'sort_order' => 20],
        ['code' => 'SICK_HALF', 'name' => 'Sick Leave (Half Pay)', 'days_per_year' => 15, 'counting' => 'working', 'paid' => 'half', 'allow_half_day' => true, 'color' => '#fb7185', 'sort_order' => 25],
        ['code' => 'STUDY', 'name' => 'Study Leave', 'days_per_year' => 7, 'counting' => 'working', 'requires_document' => true, 'allow_half_day' => true, 'color' => '#0ea5e9', 'sort_order' => 30],
        ['code' => 'COMPASSIONATE', 'name' => 'Compassionate Leave', 'days_per_year' => 5, 'counting' => 'working', 'color' => '#8b5cf6', 'sort_order' => 40],
        ['code' => 'MATERNITY', 'name' => 'Maternity Leave', 'days_per_year' => 90, 'counting' => 'calendar', 'requires_document' => true, 'gender' => 'female', 'color' => '#ec4899', 'sort_order' => 50],
        ['code' => 'PATERNITY', 'name' => 'Paternity Leave', 'days_per_year' => 14, 'counting' => 'calendar', 'requires_document' => true, 'gender' => 'male', 'color' => '#14b8a6', 'sort_order' => 55],
        ['code' => 'FAMILY', 'name' => 'Family Responsibility Leave', 'days_per_year' => 3, 'counting' => 'working', 'allow_half_day' => true, 'color' => '#f59e0b', 'sort_order' => 60],
        ['code' => 'UNPAID', 'name' => 'Unpaid Leave', 'days_per_year' => null, 'counting' => 'working', 'paid' => 'unpaid', 'has_balance' => false, 'allow_half_day' => true, 'color' => '#64748b', 'sort_order' => 70],
        ['code' => 'HOURLY', 'name' => 'Hourly Leave', 'days_per_year' => null, 'counting' => 'working', 'allow_hours' => true, 'balance_code' => 'ANNUAL', 'color' => '#a855f7', 'sort_order' => 80],
    ];

    protected function casts(): array
    {
        return [
            'days_per_year' => 'float',
            'opening_days' => 'float',
            'monthly_accrual' => 'float',
            'carry_forward_cap' => 'float',
            'requires_document' => 'boolean',
            'accrues_monthly' => 'boolean',
            'carry_forward' => 'boolean',
            'has_balance' => 'boolean',
            'allow_half_day' => 'boolean',
            'allow_hours' => 'boolean',
            'active' => 'boolean',
            'customised' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('leave');
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('active', true);
    }

    public function balanceType(): BelongsTo
    {
        return $this->belongsTo(self::class, 'balance_type_id');
    }

    public function requests(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /** The type whose balance this type draws from (Hourly → Annual), or itself. */
    public function ledgerType(): self
    {
        return $this->balance_type_id ? ($this->balanceType ?? $this) : $this;
    }

    /** Whether requests of this type are checked against / deducted from a balance. */
    public function tracksBalance(): bool
    {
        return $this->ledgerType()->has_balance;
    }

    /**
     * Maternity and paternity stay separate, gender-gated types rather than one shared "parental" balance
     * (HR-FEATURES §3.1): eligibility follows the employee's gender, so nobody holds both, and
     * LeaveWorkflow::assess() also refuses either one when the employee already has the other that year.
     */
    public const PARENTAL_CODES = ['MATERNITY', 'PATERNITY'];

    public function eligible(?Employee $employee): bool
    {
        return ! $this->gender || ($employee && $employee->gender === $this->gender);
    }

    /**
     * Whether the company leave settings (annual_leave_days, monthly_accrual) drive this type: the seeded
     * Annual Leave type until HR edits it. An edited type keeps its own values.
     */
    public function followsCompanySettings(): bool
    {
        return $this->code === 'ANNUAL' && ! $this->customised;
    }

    /** Days per year: the company's annual_leave_days for Annual Leave unless the type was edited. */
    public function entitlement(?Company $company): ?float
    {
        if ($company && $this->followsCompanySettings() && is_numeric($company->setting('annual_leave_days'))) {
            return (float) $company->setting('annual_leave_days');
        }

        return $this->days_per_year !== null ? (float) $this->days_per_year : null;
    }

    /** New-hire opening balance: one month's accrual for Annual Leave following the company settings. */
    public function openingDays(?Company $company): ?float
    {
        if ($this->followsCompanySettings()) {
            return $this->monthlyAccrual($company);
        }

        return $this->opening_days !== null ? (float) $this->opening_days : null;
    }

    public function monthlyAccrual(?Company $company): float
    {
        // Annual Leave not edited by HR: company monthly_accrual → company annual_leave_days / 12.
        if ($company && $this->followsCompanySettings()) {
            $setting = $company->setting('monthly_accrual');
            if (is_numeric($setting) && (float) $setting > 0) {
                return (float) $setting;
            }
            $days = $this->entitlement($company);

            return $days ? round($days / 12, 2) : 1.75;
        }
        // Type value → company setting → days_per_year / 12 → 1.75 (Kenyan statutory 21 days / 12).
        if ($this->monthly_accrual !== null && (float) $this->monthly_accrual > 0) {
            return (float) $this->monthly_accrual;
        }
        $setting = $company?->setting('monthly_accrual');
        if (is_numeric($setting) && (float) $setting > 0) {
            return (float) $setting;
        }

        return $this->days_per_year ? round((float) $this->days_per_year / 12, 2) : 1.75;
    }

    /** Maximum days carried into the next year (excess is forfeited); null when the type does not carry forward. */
    public function carryForwardCap(Company $company): ?float
    {
        if (! $this->carry_forward) {
            return null;
        }

        return (float) ($this->carry_forward_cap ?? $company->setting('carry_forward_cap', 5));
    }

    /** Create the default catalogue for a company (idempotent). */
    public static function seedDefaults(Company $company): void
    {
        foreach (self::DEFAULTS as $row) {
            $balanceCode = $row['balance_code'] ?? null;
            unset($row['balance_code']);
            $type = static::withoutGlobalScopes()->firstOrNew(['company_id' => $company->id, 'code' => $row['code']]);
            if (! $type->exists) {
                $type->fill($row);
                $type->company_id = $company->id;
                $type->save();
            }
            if ($balanceCode && ! $type->balance_type_id) {
                $type->balance_type_id = static::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $balanceCode)->value('id');
                $type->save();
            }
        }
    }
}
