<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

class Company extends Model
{
    use HasFactory, SoftDeletes;

    /** Defaults for per-company rules that the legacy system hard-coded by company id. */
    public const DEFAULT_SETTINGS = [
        'work_start' => '07:45',
        'work_end' => '17:00',
        'hours_per_day' => 8,
        'annual_leave_days' => 21,
        'monthly_accrual' => 1.75,
        'carry_forward_cap' => 5,
        'medical_annual_limit' => 120000,
        'medical_monthly_limit' => 10000,
        'currency' => 'KES',
        'require_two_factor_roles' => ['super-admin', 'hr-admin', 'finance', 'payroll-officer'],
        'idle_timeout_minutes' => 30,
    ];

    protected $fillable = [
        'name', 'code', 'email', 'phone', 'location', 'address', 'tax_pin',
        'incorporation_number', 'incorporation_date', 'logo_path', 'brand_color', 'settings', 'is_active',
    ];

    protected $appends = ['logo_url'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'incorporation_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, Arr::get(self::DEFAULT_SETTINGS, $key, $default));
    }

    public function resolvedSettings(): array
    {
        return array_replace(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }
}
