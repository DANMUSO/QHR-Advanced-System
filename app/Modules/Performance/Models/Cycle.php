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

    /** A KPI or goal due date must fall within the cycle's start and end dates. */
    public function assertDateWithin(mixed $date, string $field = 'due_date'): void
    {
        if ($date === null || $date === '') {
            return;
        }
        $d = \Illuminate\Support\Carbon::parse($date)->startOfDay();
        if (($this->start_date && $d->lt($this->start_date)) || ($this->end_date && $d->gt($this->end_date))) {
            throw \Illuminate\Validation\ValidationException::withMessages([$field => sprintf(
                'The due date must fall within the %s cycle (%s – %s).', $this->name,
                $this->start_date?->format('d M Y') ?? '…', $this->end_date?->format('d M Y') ?? '…',
            )]);
        }
    }

    /** The active cycle (latest start), falling back to the most recent one. */
    public static function current(?int $companyId = null): ?self
    {
        $base = fn () => static::query()->when($companyId, fn ($q) => $q->withoutGlobalScope('company')->where('company_id', $companyId));

        return $base()->active()->orderByDesc('start_date')->first() ?? $base()->orderByDesc('start_date')->first();
    }
}
