<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataImport extends Model
{
    use BelongsToCompany;

    protected $table = 'org_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['errors' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
