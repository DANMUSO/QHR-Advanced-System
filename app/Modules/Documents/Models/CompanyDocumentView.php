<?php

namespace App\Modules\Documents\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee opened or downloaded a company document (latest version they viewed). */
class CompanyDocumentView extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['first_viewed_at' => 'datetime', 'viewed_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }
}
