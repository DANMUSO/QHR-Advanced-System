<?php

namespace App\Modules\Compliance\Models;

class MedicalCover extends ComplianceRecord
{
    protected $table = 'comp_medical_covers';

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date', 'cover_limit' => 'decimal:2'];
    }
}
