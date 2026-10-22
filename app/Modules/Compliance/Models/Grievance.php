<?php

namespace App\Modules\Compliance\Models;

class Grievance extends ComplianceRecord
{
    protected $table = 'comp_grievances';

    public const CATEGORIES = ['workplace', 'harassment', 'discrimination', 'pay', 'working_conditions', 'other'];

    protected $hidden = ['author_hash'];

    protected function casts(): array
    {
        return ['filed_date' => 'date', 'anonymous' => 'boolean'];
    }

    public function assignedTo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Employee::class, 'assigned_to_employee_id');
    }
}
