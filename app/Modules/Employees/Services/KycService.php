<?php

namespace App\Modules\Employees\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\KycCheck;
use App\Modules\Employees\Support\EmployeeRules;

/**
 * Identity (KYC) and background checks.
 *
 * Identity: an internal verification of the statutory declaration — formats, completeness, age and
 * duplicates across the company. No external registry (IPRS / KRA iTax) is called yet; the provider is
 * recorded as "internal" so a real integration can be added behind the same endpoint later.
 * Background: HR records the outcome of an offline check (e.g. police clearance certificate number).
 */
class KycService
{
    public function verifyIdentity(Employee $e, ?User $by, ?string $notes = null): KycCheck
    {
        $checks = [];
        $add = function (string $key, string $label, bool $ok, ?string $detail = null) use (&$checks) {
            $checks[] = ['key' => $key, 'label' => $label, 'passed' => $ok, 'detail' => $detail];
        };

        $add('names', 'First and last name present', filled($e->first_name) && filled($e->last_name));
        $add('national_id', 'National ID / passport format', (bool) preg_match(EmployeeRules::ID_REGEX, (string) $e->national_id),
            $e->national_id ? null : 'Missing');
        $add('kra_pin', 'KRA PIN format', (bool) preg_match(EmployeeRules::KRA_REGEX, (string) $e->kra_pin), $e->kra_pin ? null : 'Missing');
        $add('date_of_birth', 'Date of birth present and age 18+', $e->date_of_birth !== null && $e->date_of_birth->age >= 18,
            $e->date_of_birth ? "Age {$e->date_of_birth->age}" : 'Missing');
        $add('nssf', 'NSSF number recorded', filled($e->nssf_number));
        $add('shif', 'SHIF number recorded', filled($e->shif_number));

        $dupe = fn (string $col) => filled($e->{$col}) && Employee::withoutGlobalScopes()->where('company_id', $e->company_id)
            ->whereKeyNot($e->id)->whereIn('status', ['active', 'suspended'])->where($col, $e->{$col})->exists();
        $add('unique_id', 'National ID not used by another active employee', ! $dupe('national_id'));
        $add('unique_pin', 'KRA PIN not used by another active employee', ! $dupe('kra_pin'));

        $failed = collect($checks)->where('passed', false);

        return $this->record($e, 'identity', $failed->isEmpty() ? 'verified' : 'failed', [
            'checks' => $checks,
            'issues' => $failed->map(fn ($c) => $c['label'].($c['detail'] ? " ({$c['detail']})" : ''))->values()->all(),
        ], $by, $notes);
    }

    public function recordBackground(Employee $e, string $status, ?string $reference, ?string $notes, ?User $by): KycCheck
    {
        return $this->record($e, 'background', $status, null, $by, $notes, $reference);
    }

    private function record(Employee $e, string $type, string $status, ?array $result, ?User $by, ?string $notes, ?string $reference = null): KycCheck
    {
        $check = new KycCheck([
            'employee_id' => $e->id, 'check_type' => $type, 'status' => $status, 'provider' => 'internal',
            'reference' => $reference, 'result' => $result, 'notes' => $notes,
            'verified_by' => $by?->id, 'verified_at' => $status === 'pending' ? null : now(),
        ]);
        $check->forceFill(['company_id' => $e->company_id])->save();
        activity('employees')->performedOn($e)->causedBy($by)
            ->withProperties(['check' => $type, 'status' => $status, 'issues' => $result['issues'] ?? null])
            ->log(ucfirst($type).' check: '.$status);

        return $check;
    }
}
