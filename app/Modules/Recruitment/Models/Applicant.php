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

    /** Stages from which an offer can be made: the applicant has been interviewed (or already holds an offer). */
    public const OFFERABLE_STAGES = ['interview', 'offer'];

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'applicant_id');
    }

    public function interviews(): HasMany
    {
        return $this->hasMany(Interview::class, 'applicant_id');
    }
}
