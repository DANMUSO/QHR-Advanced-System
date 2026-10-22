<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Child of a company-owned Course; load through the course or scope by employee. */
class Enrollment extends Model
{
    use BelongsToCompany;

    public const STATUSES = ['enrolled', 'in_progress', 'completed', 'cancelled'];

    protected $table = 'ld_enrollments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'completed_at' => 'date:Y-m-d', 'progress' => 'integer'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
