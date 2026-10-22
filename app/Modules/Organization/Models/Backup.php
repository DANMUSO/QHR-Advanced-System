<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company data export (JSON, gzip-compressed) stored privately on the local disk. */
class Backup extends Model
{
    use BelongsToCompany;

    protected $table = 'org_backups';

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected $appends = ['size_human'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getSizeHumanAttribute(): string
    {
        $b = (int) $this->size;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($b < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? $b : number_format($b, 1)).' '.$unit;
            }
            $b /= 1024;
        }

        return '';
    }
}
