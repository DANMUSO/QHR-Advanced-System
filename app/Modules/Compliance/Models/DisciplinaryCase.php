<?php

namespace App\Modules\Compliance\Models;

class DisciplinaryCase extends ComplianceRecord
{
    protected $table = 'comp_disciplinary_cases';

    protected function casts(): array
    {
        return ['reported_date' => 'date', 'hearing_date' => 'date'];
    }
}
