<?php

namespace App\Modules\Claims\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalClaimItem extends Model
{
    protected $guarded = ['id', 'medical_claim_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
