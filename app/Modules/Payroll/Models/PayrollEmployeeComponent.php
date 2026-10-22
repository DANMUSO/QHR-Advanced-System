<?php

namespace App\Modules\Payroll\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class PayrollEmployeeComponent extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'amount' => 'float', 'rate' => 'float'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('payroll')->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeActiveBetween(Builder $q, $start, $end): Builder
    {
        return $q->whereDate('effective_from', '<=', $end)->where(fn ($w) => $w->whereNull('effective_to')->orWhereDate('effective_to', '>=', $start));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class)->withoutGlobalScope('company');
    }
}
