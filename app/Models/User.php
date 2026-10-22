<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCKOUT_MINUTES = 15;

    protected $fillable = [
        'company_id', 'name', 'email', 'password', 'is_active', 'activated_at',
        'must_change_password', 'password_changed_at', 'preferences',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'activated_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'locked_until' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'preferences' => 'array',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'is_active', 'company_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class)->withoutGlobalScope('company');
    }

    public function loginActivities(): HasMany
    {
        return $this->hasMany(LoginActivity::class);
    }

    /** Phone number for SmsChannel notifications. */
    public function routeNotificationForSms(): ?string
    {
        return $this->employee?->phone;
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Whether this user's role obliges them to use two-factor authentication. */
    public function requiresTwoFactor(): bool
    {
        if (! config('hr.enforce_two_factor')) {
            return false;
        }
        $roles = $this->company?->setting('require_two_factor_roles')
            ?? Company::DEFAULT_SETTINGS['require_two_factor_roles'];

        return $this->hasAnyRole($roles);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->preferences ?? [], $key, $default);
    }

    public function registerFailedLogin(): void
    {
        $this->failed_login_attempts++;
        if ($this->failed_login_attempts >= self::MAX_FAILED_ATTEMPTS) {
            $this->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
        }
        $this->saveQuietly();
    }

    public function clearFailedLogins(): void
    {
        $this->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->saveQuietly();
    }

    public static function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->filter()->take(2)
            ->map(fn ($p) => Str::upper(Str::substr($p, 0, 1)))->implode('');
    }
}
