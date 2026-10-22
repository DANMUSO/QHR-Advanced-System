<?php

namespace App\Modules\Documents\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An archived file of a company document (always loaded through its parent). */
class CompanyDocumentVersion extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['uploaded_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
