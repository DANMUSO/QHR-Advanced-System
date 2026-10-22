<?php

namespace App\Modules\Attendance\Models;

use App\Models\Branch;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class AttendanceGeofence extends Model
{
    use BelongsToCompany, LogsActivity;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer', 'is_active' => 'boolean'];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at'])->logOnlyDirty()->dontSubmitEmptyLogs()->useLogName('attendance');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Great-circle distance in metres (haversine). */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    public function distanceTo(float $lat, float $lng): float
    {
        return static::distance($this->latitude, $this->longitude, $lat, $lng);
    }
}
