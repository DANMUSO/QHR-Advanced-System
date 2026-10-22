<?php

namespace App\Modules\Performance\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Performance Improvement Plan. */
class Pip extends Model
{
    use BelongsToCompany, LogsActivity;

    public const FREQUENCIES = ['weekly' => 7, 'biweekly' => 14, 'monthly' => 30];

    public const OUTCOMES = ['successful', 'unsuccessful', 'escalated'];

    protected $table = 'perf_pips';

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['next_checkin_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'supervisor_id');
    }

    public function checkins(): HasMany
    {
        return $this->hasMany(PipCheckin::class)->orderByDesc('meeting_date')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['active', 'extended'], true);
    }

    public function getNextCheckinDateAttribute(): ?string
    {
        if (! $this->isOpen() || ! $this->start_date || ! $this->end_date) {
            return null;
        }
        $last = $this->relationLoaded('checkins') ? $this->checkins->max('meeting_date') : $this->checkins()->max('meeting_date');
        $from = $last ? Carbon::parse($last) : $this->start_date->copy();
        $next = $from->addDays(self::FREQUENCIES[$this->review_frequency] ?? 30);

        return ($next->greaterThan($this->end_date) ? $this->end_date : $next)->toDateString();
    }
}
