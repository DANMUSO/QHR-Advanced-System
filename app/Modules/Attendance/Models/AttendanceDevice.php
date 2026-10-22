<?php

namespace App\Modules\Attendance\Models;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendanceDevice extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'serial', 'branch_id', 'active', 'revoked_at'])
            ->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('attendance');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Issues a fresh token, stores its hash and returns the plain value (shown once). */
    public function issueToken(): string
    {
        $token = 'atd_'.Str::random(48);
        $this->forceFill(['token_hash' => static::hashToken($token), 'token_hint' => substr($token, -4)]);

        return $token;
    }
}
