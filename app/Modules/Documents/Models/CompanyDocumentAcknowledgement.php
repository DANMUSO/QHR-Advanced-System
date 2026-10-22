<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "I have read and understood" record (loaded through its company document). */
class CompanyDocumentAcknowledgement extends Model
{
    use BelongsToCompany;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withoutGlobalScope('company');
    }
}
