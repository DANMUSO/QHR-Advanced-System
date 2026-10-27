<?php

namespace App\Modules\Claims\Models;

use Illuminate\Database\Eloquent\Model;

class MedicalClaimItem extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id', 'medical_claim_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }
}
