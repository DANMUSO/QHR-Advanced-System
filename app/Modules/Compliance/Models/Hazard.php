<?php

namespace App\Modules\Compliance\Models;

class Hazard extends ComplianceRecord
{
    protected $table = 'comp_hs_hazards';

    protected function casts(): array
    {
        return ['reported_date' => 'date'];
    }
}
