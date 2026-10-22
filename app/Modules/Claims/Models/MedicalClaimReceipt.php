<?php

namespace App\Modules\Claims\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalClaimReceipt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];

    public function claim(): BelongsTo
    {
        return $this->belongsTo(MedicalClaim::class, 'medical_claim_id');
    }
}
