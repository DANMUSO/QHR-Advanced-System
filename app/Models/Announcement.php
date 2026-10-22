<?php

namespace App\Models;

use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** Company announcements. A null company_id broadcasts to every company. */
class Announcement extends Model
{
    use LogsActivity;

    protected $guarded = ['id', 'author_id'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'published_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** Audit trail: create / update / delete of announcements (who published what, and changes to it). */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('announcements')
            ->logOnly(['title', 'body', 'company_id', 'pinned', 'published_at', 'expires_at'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $event) => "Announcement {$event}: ".str($this->title)->limit(80));
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function readers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'announcement_reads')->withPivot('read_at');
    }

    public function scopeVisible(Builder $q): Builder
    {
        $companyId = Tenancy::companyId();

        return $q->where(fn ($w) => $w->whereNull('company_id')->when($companyId, fn ($x) => $x->orWhere('company_id', $companyId)));
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->whereNotNull('published_at')->where('published_at', '<=', now())
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
