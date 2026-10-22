<?php

namespace App\Modules\Employees\Support;

use App\Models\Employee;
use App\Models\EmployeeNextOfKin;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation rules shared by onboarding, editing, self-service, re-instatement and transfer.
 */
class EmployeeRules
{
    public const PHONE_REGEX = '/^\+?[0-9]{9,15}$/';

    public const KRA_REGEX = '/^[A-Za-z][0-9]{9}[A-Za-z]$/';

    public const ID_REGEX = '/^[A-Za-z0-9]{5,20}$/';

    /** Unique among the company's current (active/suspended) staff; exited/transferred records may share details. */
    public static function uniqueCurrent(string $column, int $companyId, ?int $ignoreId = null)
    {
        return Rule::unique('employees', $column)
            ->where(fn ($q) => $q->where('company_id', $companyId)->whereNull('deleted_at')->whereIn('status', ['active', 'suspended']))
            ->ignore($ignoreId);
    }

    public static function inCompany(string $table, int $companyId)
    {
        return Rule::exists($table, 'id')->where('company_id', $companyId);
    }

    public static function personal(int $companyId, ?Employee $employee = null, bool $self = false): array
    {
        $id = $employee?->id;
        $rules = [
            'phone' => ['required', 'string', 'regex:'.self::PHONE_REGEX, self::uniqueCurrent('phone', $companyId, $id)],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'divorced', 'widowed'])],
            'postal_address' => ['nullable', 'string', 'max:255'],
            'residence' => ['nullable', 'string', 'max:255'],
            'about' => ['nullable', 'string', 'max:2000'],
        ];
        if (! $self) {
            $rules += [
                'first_name' => ['required', 'string', 'max:80'],
                'last_name' => ['required', 'string', 'max:80'],
                'other_names' => ['nullable', 'string', 'max:80'],
                'email' => ['required', 'email', 'max:150', self::uniqueCurrent('email', $companyId, $id),
                    Rule::unique('users', 'email')->ignore($employee?->user_id)],
                'gender' => ['required', Rule::in(['male', 'female', 'other'])],
                'date_of_birth' => ['required', 'date', 'before_or_equal:'.now()->subYears(18)->toDateString(), 'after:1930-01-01'],
            ];
        }

        return $rules;
    }

    public static function statutory(int $companyId, ?Employee $employee = null): array
    {
        $id = $employee?->id;

        return [
            'national_id' => ['required', 'string', 'regex:'.self::ID_REGEX, self::uniqueCurrent('national_id', $companyId, $id)],
            'kra_pin' => ['nullable', 'string', 'regex:'.self::KRA_REGEX, self::uniqueCurrent('kra_pin', $companyId, $id)],
            'nssf_number' => ['nullable', 'string', 'max:30'],
            'shif_number' => ['nullable', 'string', 'max:30'],
            'helb_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    public static function employment(int $companyId, ?Employee $employee = null): array
    {
        $supervisor = Rule::exists('employees', 'id')->where(fn ($q) => $q->where('company_id', $companyId)
            ->whereIn('status', ['active', 'suspended'])->whereNull('deleted_at'));

        return [
            'employee_number' => ['required', 'string', 'max:40',
                Rule::unique('employees', 'employee_number')->where('company_id', $companyId)->ignore($employee?->id)],
            'branch_id' => ['required', self::inCompany('branches', $companyId)],
            'department_id' => ['required', self::inCompany('departments', $companyId)],
            'position_id' => ['required', self::inCompany('positions', $companyId)],
            'employment_level_id' => ['nullable', self::inCompany('employment_levels', $companyId)],
            'primary_supervisor_id' => ['nullable', 'integer', $supervisor, ...($employee ? [Rule::notIn([$employee->id])] : [])],
            'secondary_supervisor_id' => ['nullable', 'integer', $supervisor, 'different:primary_supervisor_id',
                ...($employee ? [Rule::notIn([$employee->id])] : [])],
            'date_of_joining' => ['required', 'date', 'before_or_equal:'.now()->addYear()->toDateString()],
            'contract_type' => ['required', Rule::in(['permanent', 'contract', 'casual', 'intern'])],
            'contract_end_date' => ['nullable', 'required_unless:contract_type,permanent', 'date', 'after:date_of_joining'],
            'probation_months' => ['nullable', 'integer', 'min:0', 'max:12'],
            'has_medical_cover' => ['boolean'],
        ];
    }

    public static function family(bool $requireNextOfKin = true): array
    {
        return [
            'next_of_kin' => [$requireNextOfKin ? 'required' : 'sometimes', 'array', 'min:1', 'max:10'],
            'next_of_kin.*.id' => ['nullable', 'integer'],
            'next_of_kin.*.name' => ['required', 'string', 'max:120'],
            'next_of_kin.*.relationship' => ['required', Rule::in(EmployeeNextOfKin::RELATIONSHIPS)],
            'next_of_kin.*.phone' => ['required', 'string', 'regex:'.self::PHONE_REGEX],
            'next_of_kin.*.email' => ['nullable', 'email', 'max:150'],
            'next_of_kin.*.address' => ['nullable', 'string', 'max:255'],

            'dependants' => ['sometimes', 'array', 'max:20'],
            'dependants.*.id' => ['nullable', 'integer'],
            'dependants.*.name' => ['required', 'string', 'max:120'],
            'dependants.*.relationship' => ['required', Rule::in(['spouse', 'child', 'other'])],
            'dependants.*.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'dependants.*.phone' => ['nullable', 'string', 'regex:'.self::PHONE_REGEX],
            'dependants.*.email' => ['nullable', 'email', 'max:150'],

            'qualifications' => ['sometimes', 'array', 'max:30'],
            'qualifications.*.id' => ['nullable', 'integer'],
            'qualifications.*.level' => ['required', 'string', 'max:60'],
            'qualifications.*.title' => ['required', 'string', 'max:200'],
            'qualifications.*.institution' => ['nullable', 'string', 'max:200'],
            'qualifications.*.grade' => ['nullable', 'string', 'max:60'],
            'qualifications.*.year_completed' => ['nullable', 'integer', 'min:1950', 'max:'.now()->year],
        ];
    }

    public static function benefits(int $companyId): array
    {
        return [
            'benefit_ids' => ['sometimes', 'array'],
            'benefit_ids.*' => ['integer', self::inCompany('benefits', $companyId)],
        ];
    }

    /** Rejects reporting loops (an employee reporting to someone who reports to them) and a spouse listed for a single person. */
    public static function after(Validator $validator, ?Employee $employee): void
    {
        $data = $validator->getData();

        $spouses = collect($data['dependants'] ?? [])->where('relationship', 'spouse')->count();
        if ($spouses > 0 && ($data['marital_status'] ?? null) === 'single') {
            $validator->errors()->add('marital_status', 'Marital status is "single" but a spouse is listed under dependants.');
        }

        if (! $employee || empty($data['primary_supervisor_id'])) {
            return;
        }
        $seen = [];
        $current = (int) $data['primary_supervisor_id'];
        while ($current && ! in_array($current, $seen, true)) {
            if ($current === $employee->id) {
                $validator->errors()->add('primary_supervisor_id', 'This supervisor reports (directly or indirectly) to this employee.');

                return;
            }
            $seen[] = $current;
            $current = (int) Employee::withoutGlobalScopes()->whereKey($current)->value('primary_supervisor_id');
        }
    }

    public static function attributes(): array
    {
        return [
            'next_of_kin.*.name' => 'next of kin name', 'next_of_kin.*.relationship' => 'relationship',
            'next_of_kin.*.phone' => 'next of kin phone', 'next_of_kin.*.email' => 'next of kin email',
            'dependants.*.name' => 'dependant name', 'dependants.*.relationship' => 'dependant relationship',
            'dependants.*.date_of_birth' => 'dependant date of birth', 'dependants.*.phone' => 'dependant phone',
            'qualifications.*.level' => 'qualification level', 'qualifications.*.title' => 'qualification title',
            'qualifications.*.year_completed' => 'year completed', 'primary_supervisor_id' => 'primary supervisor',
            'secondary_supervisor_id' => 'secondary supervisor', 'kra_pin' => 'KRA PIN', 'national_id' => 'national ID',
            'branch_id' => 'branch', 'department_id' => 'department', 'position_id' => 'position',
            'employment_level_id' => 'level', 'date_of_joining' => 'joining date',
        ];
    }

    public static function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. 0712345678 or +254712345678.',
            'next_of_kin.*.phone.regex' => 'Enter a valid phone number.',
            'dependants.*.phone.regex' => 'Enter a valid phone number.',
            'kra_pin.regex' => 'KRA PIN must look like A012345678Z.',
            'national_id.regex' => 'National ID / passport must be 5–20 letters or digits.',
            'email.unique' => 'This email is already used by another employee or login.',
            'phone.unique' => 'This phone number is already used by another employee.',
            'national_id.unique' => 'This national ID is already registered to another employee.',
            'kra_pin.unique' => 'This KRA PIN is already registered to another employee.',
            'contract_end_date.required_unless' => 'An end date is required for contract, casual and intern staff.',
            'date_of_birth.before_or_equal' => 'The employee must be at least 18 years old.',
            'next_of_kin.required' => 'Add at least one next of kin.',
            'next_of_kin.min' => 'Add at least one next of kin.',
        ];
    }
}
