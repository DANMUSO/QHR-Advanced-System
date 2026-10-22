<?php

namespace App\Modules\Probation\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationReview extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'probation_end_date' => 'date',
            'extension_end_date' => 'date',
            'primary_reviewed_at' => 'datetime',
            'secondary_reviewed_at' => 'datetime',
            'hoo_reviewed_at' => 'datetime',
            'hr_reviewed_at' => 'datetime',
            'stage_index' => 'integer',
            'stages' => 'array',
            'stage_log' => 'array',
            'cycle' => 'integer',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function primaryReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'primary_reviewed_by');
    }

    public function secondaryReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'secondary_reviewed_by');
    }

    public function hooReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hoo_reviewed_by');
    }

    public function hrReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_reviewed_by');
    }
}
