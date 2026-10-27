<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class CompanyDocument extends Model
{
    use BelongsToCompany, LogsActivity, SoftDeletes;

    public const CATEGORIES = ['memo', 'newsletter', 'policy'];

    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date:Y-m-d',
            'audience_ids' => 'array',
            'requires_acknowledgement' => 'boolean',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('documents')
            ->logOnly(['category', 'title', 'effective_date', 'audience', 'audience_ids', 'requires_acknowledgement', 'version', 'file_name'])
            ->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'referred_by_department_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CompanyDocumentVersion::class)->orderByDesc('version');
    }

    public function acknowledgements(): HasMany
    {
        return $this->hasMany(CompanyDocumentAcknowledgement::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(CompanyDocumentView::class);
    }

    /** Views of the current version (a replaced file has to be opened again). */
    public function currentViews(): HasMany
    {
        return $this->views()->whereColumn('company_document_views.version', '>=', 'company_documents.version');
    }

    /** Documents addressed to an employee: all staff, their department or their branch. */
    public function scopeVisibleTo(Builder $q, Employee $employee): Builder
    {
        return $q->where($q->qualifyColumn('company_id'), $employee->company_id)->where(function ($w) use ($employee) {
            $w->where('audience', 'all');
            if ($employee->department_id) {
                $w->orWhere(fn ($d) => $d->where('audience', 'department')->whereJsonContains('audience_ids', (int) $employee->department_id));
            }
            if ($employee->branch_id) {
                $w->orWhere(fn ($b) => $b->where('audience', 'branch')->whereJsonContains('audience_ids', (int) $employee->branch_id));
            }
        });
    }

    public function isAddressedTo(Employee $employee): bool
    {
        if ((int) $employee->company_id !== (int) $this->company_id) {
            return false;
        }
        $ids = array_map('intval', $this->audience_ids ?? []);

        return match ($this->audience) {
            'department' => in_array((int) $employee->department_id, $ids, true),
            'branch' => in_array((int) $employee->branch_id, $ids, true),
            default => true,
        };
    }

    /** Active employees this document is addressed to. */
    public function audienceQuery(): Builder
    {
        $q = Employee::withoutGlobalScope('company')->where('company_id', $this->company_id)->where('status', 'active');
        $ids = array_map('intval', $this->audience_ids ?? []) ?: [0];

        return match ($this->audience) {
            'department' => $q->whereIn('department_id', $ids),
            'branch' => $q->whereIn('branch_id', $ids),
            default => $q,
        };
    }
}
