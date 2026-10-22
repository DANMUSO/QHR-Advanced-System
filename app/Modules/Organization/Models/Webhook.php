<?php

namespace App\Modules\Organization\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Webhook extends Model
{
    use BelongsToCompany;

    protected $table = 'org_webhooks';

    protected $guarded = ['id', 'company_id', 'last_triggered_at', 'last_status'];

    protected $hidden = ['secret'];

    protected $appends = ['has_secret'];

    public const EVENTS = [
        'employee.created' => 'Employee added',
        'employee.updated' => 'Employee updated',
        'employee.exited' => 'Employee exited',
        'organization.changed' => 'Organisation structure changed',
        'ping' => 'Test ping',
    ];

    protected function casts(): array
    {
        return ['events' => 'array', 'is_active' => 'boolean', 'secret' => 'encrypted', 'last_triggered_at' => 'datetime'];
    }

    public function getHasSecretAttribute(): bool
    {
        return ! empty($this->attributes['secret']);
    }
}
