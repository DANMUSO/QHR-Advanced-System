<?php

namespace App\Modules\Attendance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AttendancePunch extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'punched_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'float',
            'distance_m' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceDevice::class, 'device_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
