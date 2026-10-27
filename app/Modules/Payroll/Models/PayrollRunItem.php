<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payslip line. Child of PayrollRunEmployee (no company_id; always loaded through the parent). */
class PayrollRunItem extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['taxable' => 'boolean', 'amount' => 'float', 'quantity' => 'float'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollRunEmployee::class, 'payroll_run_employee_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class)->withoutGlobalScope('company');
    }
}
