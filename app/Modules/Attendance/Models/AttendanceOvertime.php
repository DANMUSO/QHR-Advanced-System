<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceOvertime extends Model
{
    use BelongsToCompany;

    protected $table = 'attendance_overtime';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'decided_at' => 'datetime', 'paid_at' => 'datetime', 'rate' => 'float', 'amount' => 'float', 'minutes' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function getHoursAttribute(): float
    {
        return round($this->minutes / 60, 2);
    }
}
