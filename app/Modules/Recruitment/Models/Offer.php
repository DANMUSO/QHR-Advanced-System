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

    /** A sent offer lapses after its expiry date (it is still valid on the expiry day itself). */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->copy()->endOfDay()->isPast();
    }

    /** The status as shown: a sent offer past its expiry reads as expired. */
    public function effectiveStatus(): string
    {
        return $this->status === 'sent' && $this->isExpired() ? 'expired' : $this->status;
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class, 'applicant_id')->withTrashed();
    }
}
