<?php

namespace App\Modules\Compliance\Models;

class DataRequest extends ComplianceRecord
{
    protected $table = 'comp_data_requests';

    protected function casts(): array
    {
        return ['submitted_date' => 'date', 'due_date' => 'date', 'completed_at' => 'datetime'];
    }
}
