<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class PayrollCalendar extends Model
{
    use BelongsToCompany;

    protected $table = 'payroll_calendar';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period' => 'date:Y-m-d', 'cutoff_date' => 'date:Y-m-d', 'pay_date' => 'date:Y-m-d'];
    }
}
