<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certification extends Model
{
    use BelongsToCompany;

    protected $table = 'ld_certifications';

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['status'];

    protected function casts(): array
    {
        return ['issue_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** valid | expiring_soon (within 60 days) | expired */
    public function getStatusAttribute(): string
    {
        if (! $this->expiry_date) {
            return 'valid';
        }
        if ($this->expiry_date->isPast()) {
            return 'expired';
        }

        return $this->expiry_date->lte(now()->addDays(60)) ? 'expiring_soon' : 'valid';
    }
}
