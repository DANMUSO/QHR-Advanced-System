<?php

namespace App\Modules\Organization\Models;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Help-desk ticket raised by a company user to the platform support team (super admins). */
class SupportTicket extends Model
{
    use \App\Models\Concerns\SerializesLocalDates;

    protected $table = 'org_support_tickets';

    protected $guarded = ['id'];

    public const CATEGORIES = ['technical' => 'Technical', 'access' => 'Access / permissions', 'payroll' => 'Payroll', 'leave' => 'Leave', 'data' => 'Data correction', 'other' => 'Other'];

    public const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const STATUSES = ['open', 'in_progress', 'resolved', 'closed'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(SupportReply::class, 'ticket_id')->oldest();
    }

    public static function nextNumber(): string
    {
        $last = static::query()->max('id') ?? 0;

        return 'TCK-'.str_pad((string) ($last + 1001), 5, '0', STR_PAD_LEFT);
    }
}
