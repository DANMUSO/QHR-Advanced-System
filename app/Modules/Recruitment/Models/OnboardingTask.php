<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends RecruitmentModel
{
    protected $table = 'recruitment_onboarding_tasks';

    protected function casts(): array
    {
        return ['due_date' => 'date', 'completed_at' => 'datetime', 'is_mandatory' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
