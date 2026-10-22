<?php

namespace App\Modules\Employees\Services;

use App\Models\Benefit;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Persists employee records and their child rows (next of kin, dependants, qualifications, benefits).
 */
class EmployeeService
{
    public const PERSONAL = ['first_name', 'last_name', 'other_names', 'email', 'phone', 'gender', 'date_of_birth',
        'marital_status', 'postal_address', 'residence', 'about'];

    public const STATUTORY = ['national_id', 'kra_pin', 'nssf_number', 'shif_number', 'helb_number'];

    public const EMPLOYMENT = ['employee_number', 'branch_id', 'department_id', 'position_id', 'employment_level_id',
        'primary_supervisor_id', 'secondary_supervisor_id', 'date_of_joining', 'contract_type', 'contract_end_date',
        'probation_months', 'has_medical_cover'];

    public const NOK_FIELDS = ['name', 'relationship', 'phone', 'email', 'address'];

    public const DEPENDANT_FIELDS = ['name', 'relationship', 'date_of_birth', 'phone', 'email'];

    public const QUALIFICATION_FIELDS = ['level', 'title', 'institution', 'grade', 'year_completed'];

    public function __construct(public AccountService $accounts, public ContractService $contracts) {}

    /** Onboards a new employee. Caller wraps in a transaction when combining with other work. */
    public function create(array $data, int $companyId): Employee
    {
        return DB::transaction(function () use ($data, $companyId) {
            $employee = new Employee(Arr::only($data, [...self::PERSONAL, ...self::STATUTORY, ...self::EMPLOYMENT]));
            $employee->forceFill(['company_id' => $companyId, 'status' => 'active']);
            $this->normalise($employee);
            $this->applyProbation($employee, true);
            $employee->save();

            $this->syncChildren($employee, $data, log: false);
            $this->accounts->syncSupervisorRoleById($employee->primary_supervisor_id);
            $this->contracts->fromEmployment($employee, auth()->user());

            if (! empty($data['create_login'])) {
                $this->accounts->createLogin($employee);
            }

            return $employee;
        });
    }

    /** Updates a record from HR (all fields) or self-service (pass the allowed $fields). */
    public function update(Employee $employee, array $data, ?array $fields = null): Employee
    {
        return DB::transaction(function () use ($employee, $data, $fields) {
            $fields ??= [...self::PERSONAL, ...self::STATUTORY, ...self::EMPLOYMENT];
            $oldSupervisor = $employee->primary_supervisor_id;

            $employee->fill(Arr::only($data, $fields));
            $this->normalise($employee);
            $this->applyProbation($employee, false);
            $positionChanged = $employee->isDirty('position_id');
            $supervisorChanged = $employee->isDirty('primary_supervisor_id');
            $identityChanged = $employee->isDirty(['first_name', 'last_name', 'email']);
            $employee->save();

            $this->syncChildren($employee, $data);

            if ($identityChanged) {
                $this->accounts->syncIdentity($employee);
            }
            if ($positionChanged) {
                $this->accounts->syncSupervisorRole($employee);
            }
            if ($supervisorChanged) {
                $this->accounts->syncSupervisorRoleById($employee->primary_supervisor_id);
                $this->accounts->syncSupervisorRoleById($oldSupervisor);
            }

            return $employee;
        });
    }

    /** Syncs whichever child collections are present in $data. */
    public function syncChildren(Employee $employee, array $data, bool $log = true): void
    {
        $sets = [
            'next_of_kin' => ['nextOfKin', self::NOK_FIELDS, 'Updated next of kin'],
            'dependants' => ['dependants', self::DEPENDANT_FIELDS, 'Updated dependants'],
            'qualifications' => ['qualifications', self::QUALIFICATION_FIELDS, 'Updated qualifications'],
        ];
        foreach ($sets as $key => [$relation, $fields, $message]) {
            if (array_key_exists($key, $data) && is_array($data[$key])) {
                $changed = $this->syncMany($employee, $relation, $data[$key], $fields);
                if ($changed && $log) {
                    activity('employees')->performedOn($employee)
                        ->withProperties([$key => $employee->{$relation}()->get(['id', ...$fields])->toArray()])->log($message);
                }
            }
        }

        if (array_key_exists('benefit_ids', $data) && is_array($data['benefit_ids'])) {
            $ids = Benefit::withoutGlobalScope('company')->where('company_id', $employee->company_id)
                ->whereIn('id', $data['benefit_ids'])->pluck('id')->all();
            $result = $employee->benefits()->sync($ids);
            if ($log && (count($result['attached']) || count($result['detached']))) {
                activity('employees')->performedOn($employee)->withProperties([
                    'benefits' => Benefit::withoutGlobalScope('company')->whereIn('id', $ids)->pluck('name')->all(),
                ])->log('Updated benefits');
            }
        }
    }

    /** Upserts rows by id, creates new ones and deletes the missing ones. Returns whether anything changed. */
    private function syncMany(Employee $employee, string $relation, array $rows, array $fields): bool
    {
        $existing = $employee->{$relation}()->get()->keyBy('id');
        $keep = [];
        $changed = false;

        foreach ($rows as $row) {
            $attrs = Arr::only($row, $fields);
            $id = $row['id'] ?? null;
            if ($id && $existing->has($id)) {
                $model = $existing[$id]->fill($attrs);
                $changed = $changed || $model->isDirty();
                $model->save();
                $keep[] = $model->id;
            } else {
                $keep[] = $employee->{$relation}()->create($attrs)->id;
                $changed = true;
            }
        }

        $remove = $existing->keys()->diff($keep);
        if ($remove->isNotEmpty()) {
            $employee->{$relation}()->whereIn('id', $remove)->delete();
            $changed = true;
        }

        return $changed;
    }

    /** Builds a new employment record (re-instatement / transfer) carrying over the personal file. */
    public function successor(Employee $from, array $employment, int $companyId): Employee
    {
        $to = new Employee(Arr::only($from->getAttributes(), [...self::PERSONAL, ...self::STATUTORY, 'photo_path', 'cover_path', 'has_medical_cover']));
        $to->fill(Arr::only($employment, self::EMPLOYMENT));
        $to->forceFill(['company_id' => $companyId, 'status' => 'active', 'previous_employee_id' => $from->id]);
        $this->normalise($to);
        $this->applyProbation($to, true);
        $to->save();

        foreach ($from->nextOfKin()->get() as $row) {
            $to->nextOfKin()->create(Arr::only($row->getAttributes(), self::NOK_FIELDS));
        }
        foreach ($from->dependants()->get() as $row) {
            $to->dependants()->create(Arr::only($row->getAttributes(), self::DEPENDANT_FIELDS));
        }
        foreach ($from->qualifications()->get() as $row) {
            $to->qualifications()->create(Arr::only($row->getAttributes(), self::QUALIFICATION_FIELDS));
        }

        // Benefits are per company: keep the same ones, or match by name in the target company.
        $names = DB::table('benefit_employee')->join('benefits', 'benefits.id', '=', 'benefit_employee.benefit_id')
            ->where('benefit_employee.employee_id', $from->id)->pluck('benefits.name');
        if ($names->isNotEmpty()) {
            $to->benefits()->sync(Benefit::withoutGlobalScope('company')->where('company_id', $companyId)->whereIn('name', $names)->pluck('id'));
        }

        return $to;
    }

    /**
     * Points the given employee's direct reports at new supervisors.
     * $map = [reportId => supervisorId|null]; reports not in the map get $default (null = flagged "without supervisor").
     * Returns [reportId => newSupervisorId|null].
     */
    public function reassignReports(Employee $employee, array $map, ?int $default): array
    {
        $result = [];
        $reports = Employee::withoutGlobalScope('company')->where('primary_supervisor_id', $employee->id)
            ->whereIn('status', ['active', 'suspended'])->get();

        foreach ($reports as $report) {
            $target = array_key_exists($report->id, $map) ? $map[$report->id] : $default;
            if ((int) $target === $report->id || (int) $target === $employee->id) {
                $target = null;
            }
            $report->primary_supervisor_id = $target ?: null;
            if ($report->secondary_supervisor_id === $report->primary_supervisor_id) {
                $report->secondary_supervisor_id = null;
            }
            $report->save();
            $result[$report->id] = $report->primary_supervisor_id;
        }

        // Secondary-supervisor links to someone who left are simply cleared.
        Employee::withoutGlobalScope('company')->where('secondary_supervisor_id', $employee->id)->get()
            ->each(fn (Employee $e) => $e->forceFill(['secondary_supervisor_id' => null])->save());

        foreach (array_unique(array_filter($result)) as $supervisorId) {
            $this->accounts->syncSupervisorRoleById($supervisorId);
        }

        return $result;
    }

    /** Probation end date = joining date + months; status only touched while still in the default states. */
    public function applyProbation(Employee $employee, bool $fresh): void
    {
        if (! $fresh && ! $employee->isDirty(['probation_months', 'date_of_joining'])) {
            return;
        }
        $months = (int) $employee->probation_months;
        $end = $months > 0 && $employee->date_of_joining ? $employee->date_of_joining->copy()->addMonths($months) : null;
        $employee->probation_end_date = $end;

        if ($fresh || in_array($employee->probation_status, [null, 'none', 'on_probation'], true)) {
            $employee->probation_status = ! $end ? 'none' : ($end->isFuture() ? 'on_probation' : 'confirmed');
        }
    }

    private function normalise(Employee $employee): void
    {
        if ($employee->contract_type === 'permanent') {
            $employee->contract_end_date = null;
        }
        if ($employee->kra_pin) {
            $employee->kra_pin = strtoupper($employee->kra_pin);
        }
        if ($employee->email) {
            $employee->email = strtolower(trim($employee->email));
        }
        // Strip HTML/script tags from name fields to prevent injection in exports/PDFs.
        foreach (['first_name', 'last_name', 'other_names'] as $field) {
            if ($employee->{$field}) {
                $employee->{$field} = strip_tags(trim($employee->{$field}));
            }
        }
        // Default probation_months from the employment level when not explicitly set.
        if (! $employee->probation_months && $employee->employment_level_id) {
            $level = \App\Models\EmploymentLevel::withoutGlobalScope('company')->find($employee->employment_level_id);
            $employee->probation_months = (int) ($level?->probation_months ?? 0);
        }
        $employee->probation_months = (int) ($employee->probation_months ?? 0);
    }

    /** Next free employee number for a company: <CODE>-0001 style, following the highest existing number. */
    public static function nextNumber(int $companyId): string
    {
        $company = Company::withTrashed()->find($companyId);
        $prefix = strtoupper($company?->code ?: 'EMP').'-';

        $numbers = Employee::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('employee_number', 'like', $prefix.'%')->pluck('employee_number');
        $max = $numbers->map(fn ($n) => (int) preg_replace('/\D/', '', substr($n, strlen($prefix))))->max() ?? 0;

        $candidate = $max + 1;
        while (Employee::withoutGlobalScopes()->where('company_id', $companyId)
            ->where('employee_number', $prefix.str_pad((string) $candidate, 4, '0', STR_PAD_LEFT))->exists()) {
            $candidate++;
        }

        return $prefix.str_pad((string) $candidate, 4, '0', STR_PAD_LEFT);
    }
}
