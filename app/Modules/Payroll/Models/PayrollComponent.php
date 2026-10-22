<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollComponent extends Model
{
    use BelongsToCompany, LogsActivity;

    public const CATEGORIES = ['earning', 'deduction', 'employer_contribution'];

    public const CATEGORY_LABELS = ['earning' => 'Earning', 'deduction' => 'Deduction', 'employer_contribution' => 'Employer contribution'];

    public const CALCULATIONS = ['fixed', 'percent_of_basic', 'percent_of_gross', 'formula'];

    public const ROLES = ['overtime', 'bonus', 'arrears', 'leave_encashment', 'notice_pay', 'pension_employee', 'pension_employer', 'insurance_premium', 'helb', 'loan', 'advance', 'sacco'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'taxable' => 'boolean', 'pensionable' => 'boolean', 'recurring' => 'boolean', 'active' => 'boolean',
            'default_amount' => 'float', 'default_rate' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /** Engine representation; per-employee amount / rate override the defaults. */
    public function toEngine(?float $amount = null, ?float $rate = null, string $source = 'component', ?int $sourceId = null): array
    {
        return [
            'component_id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'category' => $this->category,
            'calculation' => $this->calculation, 'amount' => $amount ?? $this->default_amount, 'rate' => $rate ?? $this->default_rate,
            'formula' => $this->formula, 'taxable' => $this->taxable, 'pensionable' => $this->pensionable,
            'recurring' => $this->recurring, 'role' => $this->role, 'gl_account' => $this->gl_account,
            'source' => $source, 'source_id' => $sourceId,
        ];
    }
}
