<?php

namespace App\Modules\Compliance\Models;

class Incident extends ComplianceRecord
{
    protected $table = 'comp_incidents';

    protected function casts(): array
    {
        return ['incident_date' => 'datetime'];
    }
}
