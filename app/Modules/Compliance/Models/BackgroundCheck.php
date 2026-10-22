<?php

namespace App\Modules\Compliance\Models;

class BackgroundCheck extends ComplianceRecord
{
    protected $table = 'comp_background_checks';

    protected function casts(): array
    {
        return ['issue_date' => 'date', 'expiry_date' => 'date'];
    }
}
