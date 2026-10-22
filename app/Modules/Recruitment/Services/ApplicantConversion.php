<?php

namespace App\Modules\Recruitment\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeContract;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Requisition;
use Illuminate\Validation\ValidationException;

/**
 * Turns a hired applicant (accepted offer) into an employee. HR reviews a prefilled onboarding form; the employee is
 * then created through the normal onboarding path (EmployeeController::store → EmployeeService::create → EmployeeHired)
 * and linked back here.
 */
class ApplicantConversion
{
    /** Requisition employment type → employees.contract_type. */
    public const CONTRACT_TYPES = ['permanent' => 'permanent', 'fixed_term' => 'contract', 'casual' => 'casual', 'internship' => 'intern'];

    public static function canConvert(Applicant $applicant): bool
    {
        return ! $applicant->employee_id && ($applicant->stage === 'hired'
            || Offer::where('applicant_id', $applicant->id)->where('status', 'accepted')->exists());
    }

    public static function offerFor(Applicant $applicant): ?Offer
    {
        return Offer::where('applicant_id', $applicant->id)->orderByRaw("CASE WHEN status = 'accepted' THEN 0 ELSE 1 END")->latest('id')->first();
    }

    /** Onboarding form values taken from the applicant, their posting / requisition and the offer. */
    public function prefill(Applicant $applicant): array
    {
        $posting = $applicant->posting;
        $requisition = $posting?->requisition_id ? Requisition::find($posting->requisition_id) : null;
        $offer = self::offerFor($applicant);
        $position = $requisition?->position_id ? Position::find($requisition->position_id) : null;
        $branch = $posting?->location ? Branch::where('company_id', $applicant->company_id)->where('name', $posting->location)->first() : null;

        $parts = preg_split('/\s+/', trim((string) $applicant->name)) ?: [];
        $first = array_shift($parts) ?? '';
        $last = array_pop($parts) ?? '';

        return [
            'applicant_id' => $applicant->id,
            'company_id' => $applicant->company_id,
            'first_name' => $first,
            'last_name' => $last,
            'other_names' => implode(' ', $parts),
            'email' => $applicant->email,
            'phone' => $applicant->phone,
            'position_id' => $position?->id,
            'employment_level_id' => $position?->employment_level_id,
            'department_id' => $requisition?->department_id ?? $posting?->department_id,
            'branch_id' => $branch?->id,
            'date_of_joining' => $offer?->start_date?->toDateString(),
            'contract_type' => self::CONTRACT_TYPES[$requisition?->type ?? 'permanent'] ?? 'permanent',
            'gross_salary' => $offer ? (float) $offer->offered_salary : null,
            'employee_number' => EmployeeService::nextNumber($applicant->company_id),
            'posting_title' => $posting?->title,
            'offer_id' => $offer?->id,
        ];
    }

    /** Guard used by onboarding before the employee is created. */
    public function assertConvertible(int $applicantId, int $companyId): Applicant
    {
        $applicant = Applicant::withoutGlobalScope('company')->where('company_id', $companyId)->find($applicantId);
        if (! $applicant) {
            throw ValidationException::withMessages(['applicant_id' => 'The applicant was not found in this company.']);
        }
        if ($applicant->employee_id) {
            throw ValidationException::withMessages(['applicant_id' => "{$applicant->name} has already been converted to an employee."]);
        }
        if (! self::canConvert($applicant)) {
            throw ValidationException::withMessages(['applicant_id' => 'Only hired applicants (or accepted offers) can be converted to employees.']);
        }

        return $applicant;
    }

    /** Links the new employee to the applicant, records the agreed salary on the opening contract and fills the requisition. */
    public function link(Applicant $applicant, Employee $employee, ?float $grossSalary, ?User $by): void
    {
        $applicant->forceFill(['employee_id' => $employee->id, 'stage' => 'hired', 'hired_at' => $applicant->hired_at ?? now()])->save();

        $gross = $grossSalary ?? (($offer = self::offerFor($applicant)) ? (float) $offer->offered_salary : null);
        if ($gross !== null) {
            EmployeeContract::withoutGlobalScopes()->where('employee_id', $employee->id)->where('status', 'active')
                ->get()->each(fn (EmployeeContract $c) => $c->update(['gross_salary' => $gross]));
        }

        $posting = $applicant->posting;
        if ($posting?->requisition_id && ($req = Requisition::withoutGlobalScope('company')->find($posting->requisition_id)) && $req->status === 'approved') {
            $filled = Applicant::withoutGlobalScope('company')->where('posting_id', $posting->id)->where('stage', 'hired')->count();
            if ($filled >= $req->vacancies) {
                $req->update(['status' => 'filled']);
            }
        }

        activity('recruitment')->performedOn($applicant)->causedBy($by)
            ->withProperties(['employee_id' => $employee->id, 'employee_number' => $employee->employee_number])
            ->log('Converted applicant to employee');
    }
}
