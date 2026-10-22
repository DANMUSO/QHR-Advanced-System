<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configurable approval chain for a business process. Other modules can read the active chain with
 * Workflow::stepsFor($companyId, 'leave_request') and fall back to their built-in flow when it returns null.
 */
class Workflow extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $table = 'org_workflows';

    protected $guarded = ['id', 'company_id'];

    protected $appends = ['steps_count'];

    public const TRIGGERS = [
        'leave_request' => 'Leave request',
        'medical_claim' => 'Medical claim',
        'payroll_run' => 'Payroll run',
        'employee_exit' => 'Employee exit',
        'probation_end' => 'Probation review',
        'kpi_review' => 'KPI / performance review',
        'document_request' => 'Document request',
    ];

    /** Triggers whose module reads the configured chain (ApprovalChain::steps). Others are saved but not yet used. */
    public const HONOURED_TRIGGERS = ['leave_request', 'probation_end', 'kpi_review'];

    public const STEP_TYPES = [
        'supervisor' => 'Primary supervisor',
        'secondary_supervisor' => 'Secondary supervisor',
        'hod' => 'Head of department',
        'role' => 'Anyone with role',
    ];

    protected function casts(): array
    {
        return ['steps' => 'array', 'is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->useLogName('settings')->logOnly(['name', 'trigger', 'steps', 'is_active'])->logOnlyDirty()->dontSubmitEmptyLogs();
    }

    public function getStepsCountAttribute(): int
    {
        return count($this->steps ?? []);
    }

    /** Active approval steps for a trigger, or null when the company has not configured one. */
    public static function stepsFor(int $companyId, string $trigger): ?array
    {
        $wf = static::withoutGlobalScope('company')->where('company_id', $companyId)
            ->where('trigger', $trigger)->where('is_active', true)->latest('id')->first();

        return $wf?->steps ?: null;
    }
}
