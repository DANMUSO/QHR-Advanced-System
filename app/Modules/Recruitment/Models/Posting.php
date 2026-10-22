<?php

namespace App\Modules\Recruitment\Models;

use App\Models\Department;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Posting extends RecruitmentModel
{
    use SoftDeletes;

    protected $table = 'recruitment_postings';

    public const STATUSES = ['draft', 'published', 'paused', 'closed'];

    protected function casts(): array
    {
        return ['closing_date' => 'date'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class, 'posting_id');
    }
}
