<?php

namespace App\Modules\Payroll\Models;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's result in a payroll run (the payslip). */
class PayrollRunEmployee extends Model
{
    use BelongsToCompany;

    public const MONEY = [
        'basic_salary', 'basic_pay', 'overtime_pay', 'gross_pay', 'non_taxable_pay', 'nssf_employee', 'nssf_tier1', 'nssf_tier2',
        'nssf_employer', 'shif', 'ahl_employee', 'ahl_employer', 'pension_employee', 'pension_employer', 'allowable_deductions',
        'taxable_pay', 'tax_charged', 'personal_relief', 'insurance_relief', 'paye', 'helb', 'other_deductions',
        'reimbursements', 'total_deductions', 'net_pay', 'employer_cost',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_merge(
            array_fill_keys(self::MONEY, 'float'),
            ['days_in_period' => 'float', 'days_worked' => 'float', 'working_days' => 'float', 'unpaid_days' => 'float',
                'half_pay_days' => 'float', 'overtime_hours' => 'float',
                'payment_details' => 'array', 'inputs' => 'array', 'flags' => 'array', 'emailed_at' => 'datetime'],
        );
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id')->withoutGlobalScope('company');
    }

    public function payrollRun(): BelongsTo
    {
        return $this->run();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('company');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScope('company');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollRunItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
