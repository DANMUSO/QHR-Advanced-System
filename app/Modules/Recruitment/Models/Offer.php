<?php

namespace App\Modules\Recruitment\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends RecruitmentModel
{
    protected $table = 'recruitment_offers';

    public const STATUSES = ['draft', 'sent', 'accepted', 'declined', 'expired'];

    protected function casts(): array
    {
        return ['offered_salary' => 'decimal:2', 'start_date' => 'date', 'expiry_date' => 'date'];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id')->withTrashed();
    }
}
