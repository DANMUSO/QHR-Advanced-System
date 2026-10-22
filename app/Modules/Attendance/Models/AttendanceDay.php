<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDay extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['present', 'late', 'absent', 'on_leave', 'holiday', 'weekend', 'incomplete'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'first_in' => 'datetime',
            'last_out' => 'datetime',
            'computed_at' => 'datetime',
            'overridden_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function overrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
