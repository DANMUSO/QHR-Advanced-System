<?php

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Global Kenyan statutory table row (statute applies to every company), effective-dated. */
class StatutoryRate extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    use LogsActivity;

    public const TYPES = [
        'paye_band' => 'PAYE band', 'personal_relief' => 'Personal relief', 'insurance_relief' => 'Insurance relief',
        'nssf' => 'NSSF', 'shif' => 'SHIF', 'ahl' => 'Affordable Housing Levy', 'pension_cap' => 'Pension cap',
        'disability_exemption' => 'Disability exemption',
    ];

    protected $table = 'payroll_statutory_rates';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'lower_limit' => 'float', 'upper_limit' => 'float', 'rate' => 'float', 'employer_rate' => 'float',
            'amount' => 'float', 'cap' => 'float', 'tax_deductible' => 'boolean',
            'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeEffectiveOn(Builder $q, $date): Builder
    {
        $d = is_string($date) ? $date : $date->toDateString();

        return $q->whereDate('effective_from', '<=', $d)->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $d));
    }
}
