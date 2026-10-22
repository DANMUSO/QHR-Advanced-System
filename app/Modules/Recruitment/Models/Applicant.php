<?php

namespace App\Modules\Recruitment\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Applicant extends RecruitmentModel
{
    use SoftDeletes;

    protected $table = 'recruitment_applicants';

    public const STAGES = ['applied', 'screening', 'interview', 'offer', 'hired', 'rejected'];

    protected function casts(): array
    {
        return ['applied_at' => 'datetime', 'hired_at' => 'datetime'];
    }

    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class, 'posting_id')->withTrashed();
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'applicant_id');
    }
}
