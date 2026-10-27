<?php

namespace App\Modules\Organization\Services;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Holiday;
use App\Models\Position;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Support\EmployeeRules;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Organization\Models\DataImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * CSV bulk import / export for organisation data and basic employee records.
 * Rows are matched on a natural key (employee_number, name, title) so re-importing a file updates instead of duplicating.
 */
class DataImporter
{
    public function __construct(private EmployeeService $employees) {}

    public const TYPES = [
        'employees' => [
            'label' => 'Employees',
            'columns' => ['employee_number', 'first_name', 'last_name', 'other_names', 'email', 'phone', 'gender', 'date_of_birth', 'national_id', 'kra_pin', 'nssf_number', 'shif_number', 'branch', 'department', 'position', 'level', 'date_of_joining', 'contract_type', 'contract_end_date', 'supervisor', 'create_login', 'next_of_kin_name', 'next_of_kin_relationship', 'next_of_kin_phone'],
            'example' => ['EMP-9001', 'Jane', 'Muthoni', '', 'jane.muthoni@example.co.ke', '+254712345678', 'female', '1992-04-18', '28765432', 'A012345678Z', '2001234567', 'SH1234567', 'Nairobi HQ', 'Finance', 'Accountant', 'Mid-level', '2024-02-01', 'permanent', '', 'EMP-0001', 'yes', 'Peter Muthoni', 'father', '+254722000111'],
        ],
        'departments' => ['label' => 'Departments', 'columns' => ['name', 'email', 'phone'], 'example' => ['Internal Audit', 'audit@example.co.ke', '+254700111222']],
        'branches' => ['label' => 'Branches', 'columns' => ['name', 'location', 'address', 'email', 'phone'], 'example' => ['Eldoret', 'Eldoret Town', 'P.O. Box 12-30100', 'eldoret@example.co.ke', '+254700333444']],
        'positions' => ['label' => 'Positions', 'columns' => ['title', 'level', 'is_supervisor'], 'example' => ['Senior Accountant', 'Senior', 'no']],
        'levels' => ['label' => 'Employment levels', 'columns' => ['name', 'probation_months', 'probation_review_days'], 'example' => ['Graduate Trainee', '6', '30']],
        'holidays' => ['label' => 'Holidays', 'columns' => ['name', 'date', 'recurring'], 'example' => ['Company Founders Day', '2026-11-20', 'yes']],
    ];

    public function template(string $type): string
    {
        $def = self::TYPES[$type];

        return $this->csv([$def['columns'], $def['example']]);
    }

    public function export(string $type, int $companyId): string
    {
        $cols = self::TYPES[$type]['columns'];
        $rows = match ($type) {
            'employees' => Employee::withoutGlobalScope('company')->with(['branch', 'department', 'position', 'level', 'nextOfKin'])
                ->where('company_id', $companyId)->orderBy('employee_number')->get()
                ->map(fn ($e) => [
                    $e->employee_number, $e->first_name, $e->last_name, $e->other_names, $e->email, $e->phone, $e->gender,
                    $e->date_of_birth?->toDateString(), $e->national_id, $e->kra_pin, $e->nssf_number, $e->shif_number,
                    $e->branch?->name, $e->department?->name, $e->position?->title, $e->level?->name,
                    $e->date_of_joining?->toDateString(), $e->contract_type, $e->contract_end_date?->toDateString(), '', '',
                    $e->nextOfKin->first()?->name, $e->nextOfKin->first()?->relationship, $e->nextOfKin->first()?->phone,
                ]),
            'departments' => Department::withoutGlobalScope('company')->where('company_id', $companyId)->orderBy('name')->get()->map(fn ($d) => [$d->name, $d->email, $d->phone]),
            'branches' => Branch::withoutGlobalScope('company')->where('company_id', $companyId)->orderBy('name')->get()->map(fn ($b) => [$b->name, $b->location, $b->address, $b->email, $b->phone]),
            'positions' => Position::withoutGlobalScope('company')->with('level')->where('company_id', $companyId)->orderBy('title')->get()->map(fn ($p) => [$p->title, $p->level?->name, $p->is_supervisor ? 'yes' : 'no']),
            'levels' => EmploymentLevel::withoutGlobalScope('company')->where('company_id', $companyId)->orderBy('name')->get()->map(fn ($l) => [$l->name, $l->probation_months, $l->probation_review_days]),
            'holidays' => Holiday::query()->where('company_id', $companyId)->orderBy('date')->get()->map(fn ($h) => [$h->name, $h->date->toDateString(), $h->recurring ? 'yes' : 'no']),
        };

        return $this->csv(array_merge([$cols], $rows->all()));
    }

    /** Processes an uploaded CSV and records the outcome on $import. */
    public function import(DataImport $import, string $path): DataImport
    {
        try {
            [$header, $rows] = $this->read($path);
            $expected = self::TYPES[$import->type]['columns'];
            $required = array_slice($expected, 0, $import->type === 'employees' ? 3 : 1);
            if ($missing = array_diff($required, $header)) {
                return $this->finish($import, 0, 0, 0, [['row' => 1, 'message' => 'Missing column(s): '.implode(', ', $missing).'. Download the template for the expected layout.']], 'failed');
            }

            $ok = 0;
            $errors = [];
            $hires = [];
            foreach ($rows as $i => $values) {
                $line = $i + 2;
                $row = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
                $row = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $row);
                if (! array_filter($row, fn ($v) => $v !== '' && $v !== null)) {
                    continue; // blank line
                }
                try {
                    if ($hired = DB::transaction(fn () => $this->importRow($import->type, $import->company_id, $row))) {
                        $hires[] = $hired;
                    }
                    $ok++;
                } catch (Throwable $e) {
                    $errors[] = ['row' => $line, 'message' => $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->implode(' ') : $e->getMessage()];
                }
            }
            $total = $ok + count($errors);

            // Same follow-up automation as a single hire (appraisal enrolment, probation review, pay profile, ...).
            foreach ($hires as $employee) {
                SideEffects::dispatch(new EmployeeHired($employee));
            }

            return $this->finish($import, $total, $ok, count($errors), array_slice($errors, 0, 200), $ok === 0 && $total > 0 ? 'failed' : 'completed');
        } catch (Throwable $e) {
            report($e);

            return $this->finish($import, 0, 0, 0, [['row' => 0, 'message' => 'Could not read the file: '.$e->getMessage()]], 'failed');
        }
    }

    // ----------------------------------------------------------------------------------------

    /** Imports one row; returns a newly hired Employee (for EmployeeHired) or null. */
    private function importRow(string $type, int $companyId, array $row): ?Employee
    {
        $bool = fn ($v) => in_array(strtolower((string) $v), ['1', 'yes', 'y', 'true'], true);
        $find = function (string $class, string $column, ?string $value, string $label) use ($companyId) {
            if ($value === null || $value === '') {
                return null;
            }
            $id = $class::withoutGlobalScope('company')->where('company_id', $companyId)->where($column, $value)->value('id');
            if (! $id) {
                throw new \RuntimeException("Unknown {$label} \"{$value}\".");
            }

            return $id;
        };

        switch ($type) {
            case 'departments':
                $this->validate($row, ['name' => 'required|max:120', 'email' => 'nullable|email', 'phone' => 'nullable|max:30']);
                Department::withoutGlobalScope('company')->updateOrCreate(['company_id' => $companyId, 'name' => $row['name']], ['email' => $row['email'] ?: null, 'phone' => $row['phone'] ?: null]);
                break;

            case 'branches':
                $this->validate($row, ['name' => 'required|max:120', 'email' => 'nullable|email', 'phone' => 'nullable|max:30']);
                Branch::withoutGlobalScope('company')->updateOrCreate(['company_id' => $companyId, 'name' => $row['name']], [
                    'location' => $row['location'] ?? null ?: null, 'address' => $row['address'] ?? null ?: null, 'email' => $row['email'] ?? null ?: null, 'phone' => $row['phone'] ?? null ?: null,
                ]);
                break;

            case 'levels':
                $this->validate($row, ['name' => 'required|max:120', 'probation_months' => 'nullable|integer|min:0|max:24', 'probation_review_days' => 'nullable|integer|min:0|max:365']);
                EmploymentLevel::withoutGlobalScope('company')->updateOrCreate(['company_id' => $companyId, 'name' => $row['name']], [
                    'probation_months' => (int) ($row['probation_months'] ?: 6), 'probation_review_days' => (int) ($row['probation_review_days'] ?: 30),
                ]);
                break;

            case 'positions':
                $this->validate($row, ['title' => 'required|max:120']);
                Position::withoutGlobalScope('company')->updateOrCreate(['company_id' => $companyId, 'title' => $row['title']], [
                    'employment_level_id' => $find(EmploymentLevel::class, 'name', $row['level'] ?? null, 'level'),
                    'is_supervisor' => $bool($row['is_supervisor'] ?? ''),
                ]);
                break;

            case 'holidays':
                $this->validate($row, ['name' => 'required|max:120', 'date' => 'required|date']);
                Holiday::updateOrCreate(['company_id' => $companyId, 'name' => $row['name'], 'date' => Carbon::parse($row['date'])->toDateString()], ['recurring' => $bool($row['recurring'] ?? '')]);
                break;

            case 'employees':
                $this->validate($row, ['employee_number' => 'required|max:40']);
                $attrs = [
                    'first_name' => $row['first_name'] ?? null ?: null, 'last_name' => $row['last_name'] ?? null ?: null, 'other_names' => $row['other_names'] ?? null ?: null,
                    'email' => $row['email'] ?? null ?: null, 'phone' => $row['phone'] ?? null ?: null, 'gender' => strtolower($row['gender'] ?? '') ?: null,
                    'date_of_birth' => ($row['date_of_birth'] ?? '') ?: null, 'national_id' => $row['national_id'] ?? null ?: null,
                    'kra_pin' => $row['kra_pin'] ?? null ?: null, 'nssf_number' => $row['nssf_number'] ?? null ?: null, 'shif_number' => $row['shif_number'] ?? null ?: null,
                    'branch_id' => $find(Branch::class, 'name', $row['branch'] ?? null, 'branch'),
                    'department_id' => $find(Department::class, 'name', $row['department'] ?? null, 'department'),
                    'position_id' => $find(Position::class, 'title', $row['position'] ?? null, 'position'),
                    'employment_level_id' => $find(EmploymentLevel::class, 'name', $row['level'] ?? null, 'level'),
                    'primary_supervisor_id' => $this->supervisorId($companyId, $row['supervisor'] ?? null, $row['employee_number']),
                    'date_of_joining' => ($row['date_of_joining'] ?? '') ?: null,
                    'contract_type' => strtolower($row['contract_type'] ?? '') ?: null,
                    'contract_end_date' => ($row['contract_end_date'] ?? '') ?: null,
                ];
                if (($row['next_of_kin_name'] ?? '') !== '' || ($row['next_of_kin_phone'] ?? '') !== '') {
                    $attrs['next_of_kin'] = [[
                        'name' => $row['next_of_kin_name'] ?? '', 'phone' => $row['next_of_kin_phone'] ?? '',
                        'relationship' => strtolower($row['next_of_kin_relationship'] ?? '') ?: 'relative',
                    ]];
                }
                $employee = Employee::withoutGlobalScope('company')->withTrashed()->where('company_id', $companyId)->where('employee_number', $row['employee_number'])->first();
                if ($employee) {
                    // Re-import updates the record through the same service and rules as the HR edit form
                    // (only the columns filled in on the row are checked and changed).
                    $attrs = array_filter($attrs, fn ($v) => $v !== null);
                    if ($employee->nextOfKin()->exists()) {
                        unset($attrs['next_of_kin']); // never replace a next of kin kept on the record
                    }
                    $this->validateEmployee($attrs + ['employee_number' => $row['employee_number']], $companyId, $employee);
                    $this->employees->update($employee, $attrs);
                    if ($bool($row['create_login'] ?? '') && ! $employee->user_id) {
                        $this->employees->accounts->createLogin($employee);
                    }

                    return null;
                }

                // New hire: same onboarding as Add employee (probation, contract, supervisor role, optional login).
                $attrs['date_of_joining'] ??= now()->toDateString();
                $attrs['contract_type'] ??= 'permanent';
                // Same rules as Add employee: national ID, KRA PIN and phone formats, required fields, next of kin.
                $this->validateEmployee($attrs + ['employee_number' => $row['employee_number']], $companyId, null);
                $employee = $this->employees->create($attrs + [
                    'employee_number' => $row['employee_number'],
                    'create_login' => $bool($row['create_login'] ?? ''),
                ], $companyId);
                activity('employees')->performedOn($employee)->log('Onboarded employee (bulk import)');

                return $employee;
        }

        return null;
    }

    /** Supervisor by employee number or work email in the same company. */
    private function supervisorId(int $companyId, ?string $value, string $self): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (strcasecmp($value, $self) === 0) {
            throw new \RuntimeException('An employee cannot supervise themselves.');
        }
        $id = Employee::withoutGlobalScope('company')->where('company_id', $companyId)->where('status', 'active')
            ->where(fn ($q) => $q->where('employee_number', $value)->orWhere('email', strtolower($value)))->value('id');
        if (! $id) {
            throw new \RuntimeException("Unknown supervisor \"{$value}\" (use an active employee's number or email; list supervisors above their team in the file).");
        }

        return $id;
    }

    /**
     * Validates an employee row with the rules of the HR form (EmployeeRules). A new hire is checked in full;
     * an update only on the columns the row fills in.
     */
    private function validateEmployee(array $data, int $companyId, ?Employee $employee): void
    {
        $rules = EmployeeRules::personal($companyId, $employee)
            + EmployeeRules::statutory($companyId, $employee)
            + EmployeeRules::employment($companyId, $employee)
            + EmployeeRules::family(requireNextOfKin: $employee === null);
        if ($employee) {
            $rules = array_filter($rules, fn ($r, $key) => array_key_exists(explode('.', $key)[0], $data), ARRAY_FILTER_USE_BOTH);
        }
        $validator = Validator::make($data, $rules, EmployeeRules::messages(), EmployeeRules::attributes());
        $validator->after(fn ($v) => EmployeeRules::after($v, $employee));
        $validator->validate();
    }

    private function validate(array $row, array $rules): void
    {
        Validator::make($row, $rules)->validate();
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function read(string $path): array
    {
        $fh = fopen($path, 'r');
        $header = null;
        $rows = [];
        while (($values = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($header === null) {
                $values[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $values[0]); // strip UTF-8 BOM
                $header = array_map(fn ($h) => str((string) $h)->trim()->lower()->snake()->replace('__', '_')->toString(), $values);
                continue;
            }
            $rows[] = $values;
        }
        fclose($fh);
        if (! $header) {
            throw new \RuntimeException('The file is empty.');
        }

        return [$header, $rows];
    }

    private function finish(DataImport $import, int $total, int $ok, int $failed, array $errors, string $status): DataImport
    {
        $import->forceFill(['total_rows' => $total, 'imported' => $ok, 'failed' => $failed, 'errors' => $errors ?: null, 'status' => $status])->save();

        return $import;
    }

    private function csv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        foreach ($rows as $r) {
            fputcsv($fh, array_map(fn ($v) => $v === null ? '' : (string) $v, (array) $r), ',', '"', '\\');
        }
        rewind($fh);
        $out = stream_get_contents($fh);
        fclose($fh);

        return $out;
    }
}
