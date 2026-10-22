<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** Automatic reminder rule, run daily by `organization:send-reminders`. */
class Reminder extends Model
{
    use BelongsToCompany;

    protected $table = 'org_reminders';

    protected $guarded = ['id', 'company_id', 'last_run_at', 'last_run_count'];

    public const EVENTS = [
        'contract_expiry' => 'Contract expiry',
        'probation_end' => 'Probation end',
        'document_expiry' => 'Document expiry',
        'birthday' => 'Birthday',
        'work_anniversary' => 'Work anniversary',
    ];

    public const CHANNELS = ['email' => 'Email', 'sms' => 'SMS', 'in_app' => 'In-app'];

    public const RECIPIENTS = ['hr' => 'HR team', 'supervisor' => 'Supervisor', 'employee' => 'Employee'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_run_at' => 'datetime', 'days_before' => 'integer'];
    }
}
