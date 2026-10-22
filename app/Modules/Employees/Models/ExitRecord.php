<?php

namespace App\Modules\Employees\Models;

use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Modules\Recruitment\Models\AssetAssignment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/** employee_exits with the exit-workflow columns added by this module (notice, clearance, scheduling). */
class ExitRecord extends EmployeeExit
{
    use LogsActivity;

    protected $table = 'employee_exits';

    public const CLEARANCE_ITEMS = [
        'handover' => 'Work handed over',
        'assets' => 'Company assets returned (laptop, phone, ID card)',
        'it_access' => 'System access revoked',
        'finance' => 'Finance cleared (advances, loans, imprest)',
        'hr_documents' => 'Exit documents signed',
        'exit_interview' => 'Exit interview held',
    ];

    protected function casts(): array
    {
        return [
            'exit_date' => 'date', 'notice_date' => 'date', 'completed_at' => 'datetime',
            'clearance' => 'array', 'reassignments' => 'array', 'clearance_signoffs' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('employees')->logOnly(['status', 'clearance_status', 'exit_date', 'reason'])->logOnlyDirty();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }

    /** Company assets still held by the leaver (Recruitment asset register): assigned and not returned. */
    public function outstandingAssets(): Collection
    {
        if (! class_exists(AssetAssignment::class)) {
            return collect();
        }

        return AssetAssignment::withoutGlobalScopes()->where('employee_id', $this->employee_id)->where('status', 'assigned')
            ->orderBy('assigned_date')->get(['id', 'asset_name', 'asset_tag', 'assigned_date', 'status']);
    }

    /**
     * The "assets" clearance item can only be signed off when every asset is returned, or when HR overrides it with
     * a note (recorded in clearance_signoffs.assets.override_note). Throws a 422 otherwise.
     */
    public function assertAssetsClearable(?string $overrideNote): void
    {
        $outstanding = $this->outstandingAssets();
        if ($outstanding->isNotEmpty() && ! trim((string) $overrideNote)) {
            throw ValidationException::withMessages(['assets_override_note' => 'Outstanding assets: '
                .$outstanding->map(fn ($a) => $a->asset_name.($a->asset_tag ? " ({$a->asset_tag})" : ''))->implode(', ')
                .'. Mark them returned in the asset register, or give an override note.']);
        }
    }

    public function assetsPayload(): array
    {
        return $this->outstandingAssets()->map(fn ($a) => [
            'id' => $a->id, 'asset_name' => $a->asset_name, 'asset_tag' => $a->asset_tag, 'assigned_date' => $a->assigned_date?->toDateString(),
        ])->values()->all();
    }
}
