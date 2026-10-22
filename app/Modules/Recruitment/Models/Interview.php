<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Interview extends RecruitmentModel
{
    protected $table = 'recruitment_interviews';

    public const TYPES = ['phone', 'hr', 'technical', 'panel', 'final'];

    public const STATUSES = ['scheduled', 'completed', 'cancelled', 'no_show'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id')->withTrashed();
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'interviewer_id');
    }
}
