<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Amortisation row. Child of PayrollLoan (loaded through the loan). */
class PayrollLoanInstalment extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period' => 'date:Y-m-d', 'deducted_at' => 'datetime',
            'principal' => 'float', 'interest' => 'float', 'amount' => 'float', 'balance_after' => 'float',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(PayrollLoan::class, 'payroll_loan_id')->withoutGlobalScope('company');
    }
}
