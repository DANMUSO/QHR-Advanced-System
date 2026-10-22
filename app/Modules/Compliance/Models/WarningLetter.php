<?php

namespace App\Modules\Compliance\Models;

class WarningLetter extends ComplianceRecord
{
    protected $table = 'comp_warnings';

    protected function casts(): array
    {
        return ['issued_date' => 'date', 'expiry_date' => 'date'];
    }

    public function issuedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'issued_by_user_id');
    }
}
