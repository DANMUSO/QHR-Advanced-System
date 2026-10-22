<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class EmployeeDocument extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'is_confidential' => 'boolean',
            'alert_30_sent_at' => 'datetime',
            'alert_7_sent_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('documents')
            ->logOnly(['title', 'document_type_id', 'expiry_date', 'is_confidential', 'version', 'file_name'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    /**
     * A personal file follows the person across transfers, so a document may belong to an earlier employment
     * period in another company. Bind without the company scope; DocumentAccess::canView decides access.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return static::withoutGlobalScope('company')->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmployeeDocumentVersion::class)->orderByDesc('version');
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('expiry_date')->whereBetween('expiry_date', [today()->toDateString(), today()->addDays($days)->toDateString()]);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->lt(today());
    }

    public function daysToExpiry(): ?int
    {
        return $this->expiry_date ? (int) today()->diffInDays($this->expiry_date, false) : null;
    }
}
