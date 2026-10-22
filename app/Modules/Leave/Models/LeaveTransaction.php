<?php

namespace App\Modules\Leave\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveTransaction extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    public const TYPES = ['opening', 'accrual', 'deduction', 'recall_credit', 'adjustment', 'carry_forward', 'forfeiture', 'transfer_out', 'transfer_in'];

    protected function casts(): array
    {
        return ['days' => 'float', 'effective_date' => 'date'];
    }

    public function balance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class, 'leave_balance_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
