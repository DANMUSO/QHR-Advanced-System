<?php

namespace App\Modules\Leave\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Record of a scheduled leave job per company and period; makes the jobs idempotent. */
class LeaveJobRun extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary' => 'array', 'ran_at' => 'datetime', 'records' => 'integer'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public static function hasRun(string $job, string $period, ?int $companyId): bool
    {
        return static::where('job', $job)->where('period', $period)->where('company_id', $companyId)->exists();
    }
}
