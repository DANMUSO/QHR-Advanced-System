<?php

namespace App\Modules\Organization\Support;

use App\Models\Company;

/** Company settings editable on the Settings page, with defaults for keys added after Company::DEFAULT_SETTINGS. */
class SettingsSchema
{
    public const EXTRA_DEFAULTS = [
        'overtime_multiplier_normal' => 1.5,
        'overtime_multiplier_holiday' => 2.0,
        'pay_day' => 28,
        'late_grace_minutes' => 0,
        'hr_contact_name' => null,
        'hr_contact_email' => null,
        'hr_contact_phone' => null,
    ];

    public const INTEGERS = ['annual_leave_days', 'idle_timeout_minutes', 'pay_day', 'late_grace_minutes'];

    public const FLOATS = [
        'hours_per_day', 'monthly_accrual', 'carry_forward_cap', 'medical_annual_limit', 'medical_monthly_limit',
        'overtime_multiplier_normal', 'overtime_multiplier_holiday',
    ];

    public static function defaults(): array
    {
        return array_replace(Company::DEFAULT_SETTINGS, self::EXTRA_DEFAULTS);
    }

    /** Every editable key resolved for a company (stored value, else default). */
    public static function resolve(Company $company): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $out[$key] = $company->setting($key, $default);
        }

        return $out;
    }

    public static function rules(array $roleNames): array
    {
        return [
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'hours_per_day' => ['required', 'numeric', 'min:1', 'max:24'],
            'annual_leave_days' => ['required', 'integer', 'min:0', 'max:365'],
            'monthly_accrual' => ['required', 'numeric', 'min:0', 'max:31'],
            'carry_forward_cap' => ['required', 'numeric', 'min:0', 'max:365'],
            'medical_annual_limit' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'medical_monthly_limit' => ['required', 'numeric', 'min:0', 'lte:medical_annual_limit'],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'idle_timeout_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'require_two_factor_roles' => ['present', 'array'],
            'require_two_factor_roles.*' => ['string', 'in:'.implode(',', $roleNames)],
            'overtime_multiplier_normal' => ['required', 'numeric', 'min:1', 'max:5'],
            'overtime_multiplier_holiday' => ['required', 'numeric', 'min:1', 'max:5'],
            'pay_day' => ['required', 'integer', 'min:1', 'max:31'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'hr_contact_name' => ['nullable', 'string', 'max:120'],
            'hr_contact_email' => ['nullable', 'email', 'max:190'],
            'hr_contact_phone' => ['nullable', 'string', 'max:30', 'regex:'.\App\Modules\Employees\Support\EmployeeRules::PHONE_REGEX],
        ];
    }

    /** Casts validated input to proper JSON types. */
    public static function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::INTEGERS, true)) {
                $data[$key] = (int) $value;
            } elseif (in_array($key, self::FLOATS, true)) {
                $data[$key] = (float) $value;
            }
        }
        $data['currency'] = strtoupper($data['currency']);
        $data['require_two_factor_roles'] = array_values(array_unique($data['require_two_factor_roles'] ?? []));

        return $data;
    }
}
