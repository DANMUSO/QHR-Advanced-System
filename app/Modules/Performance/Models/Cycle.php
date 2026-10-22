<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Cycle extends Model
{
    use BelongsToCompany, LogsActivity;

    public const STATUSES = ['draft', 'active', 'closed'];

    protected $table = 'perf_cycles';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'kpi_weight' => 'float', 'competency_weight' => 'float', 'max_score_pct' => 'float',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    /** The active cycle (latest start), falling back to the most recent one. */
    public static function current(?int $companyId = null): ?self
    {
        $base = fn () => static::query()->when($companyId, fn ($q) => $q->withoutGlobalScope('company')->where('company_id', $companyId));

        return $base()->active()->orderByDesc('start_date')->first() ?? $base()->orderByDesc('start_date')->first();
    }
}
