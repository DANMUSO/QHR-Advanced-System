<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One row per employee × leave type × year. Every change goes through a LeaveTransaction. */
class LeaveBalance extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected $appends = ['available'];

    /** Ledger transaction type => balance column it moves. */
    public const COLUMN = [
        'opening' => 'entitled',
        'accrual' => 'accrued',
        'deduction' => 'taken',
        'recall_credit' => 'taken',
        'adjustment' => 'adjusted',
        'carry_forward' => 'carried_forward',
        'forfeiture' => 'forfeited',
        'transfer_out' => 'adjusted', // company transfer: balance moved to the new employee record
        'transfer_in' => 'adjusted',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'entitled' => 'float',
            'accrued' => 'float',
            'taken' => 'float',
            'adjusted' => 'float',
            'carried_forward' => 'float',
            'forfeited' => 'float',
        ];
    }

    public function getAvailableAttribute(): float
    {
        return round($this->entitled + $this->accrued + $this->carried_forward + $this->adjusted - $this->taken - $this->forfeited, 2);
    }

    /** Total credited for the year before anything was taken. */
    public function getEarnedAttribute(): float
    {
        return round($this->entitled + $this->accrued + $this->carried_forward + $this->adjusted, 2);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LeaveTransaction::class);
    }
}
