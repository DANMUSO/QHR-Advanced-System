<?php

namespace App\Modules\Employees\Support;

use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeExit;
use App\Models\EmployeeNextOfKin;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Storage;

/**
 * JSON shapes for employee lists and the profile page.
 */
class EmployeePresenter
{
    public static function summary(Employee $e): array
    {
        return [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'full_name' => $e->full_name,
            'first_name' => $e->first_name,
            'last_name' => $e->last_name,
            'photo_url' => $e->photo_url,
            'email' => $e->email,
            'phone' => $e->phone,
            'gender' => $e->gender,
            'age' => $e->date_of_birth?->age,
            'marital_status' => $e->marital_status,
            'postal_address' => $e->postal_address,
            'residence' => $e->residence,
            'branch' => $e->branch?->only(['id', 'name']),
            'department' => $e->department?->only(['id', 'name']),
            'position' => $e->position?->only(['id', 'title', 'is_supervisor']),
            'primary_supervisor' => $e->primarySupervisor ? ['id' => $e->primarySupervisor->id, 'full_name' => $e->primarySupervisor->full_name, 'photo_url' => $e->primarySupervisor->photo_url] : null,
            'status' => $e->status,
            'contract_type' => $e->contract_type,
            'contract_end_date' => $e->contract_end_date?->toDateString(),
            'date_of_joining' => $e->date_of_joining?->toDateString(),
            'probation_status' => $e->probation_status,
            'probation_end_date' => $e->probation_end_date?->toDateString(),
            'years_of_service' => self::yearsOfService($e),
            'account' => self::account($e->relationLoaded('user') ? $e->user : null, $e->user_id),
            'exit' => $e->relationLoaded('exitRecord') && $e->exitRecord ? self::exit($e->exitRecord) : null,
            'leaving_on' => $e->relationLoaded('scheduledExit') ? $e->scheduledExit?->exit_date?->toDateString() : null,
            'reinstated' => $e->relationLoaded('successorRecord') ? (bool) $e->successorRecord : null,
            // Supervisor view extras (set by EmployeeController::index when supervisor_role=1)
            'direct_reports_count' => $e->getAttribute('direct_reports_count'),
            'pending_leave_count'  => $e->getAttribute('pending_leave_count'),
            'rejected_leave_count' => $e->getAttribute('rejected_leave_count'),
        ];
    }

    public static function detail(Employee $e, User $viewer, bool $sensitive): array
    {
        $e->loadMissing([
            'branch:id,name', 'department:id,name', 'position:id,title,is_supervisor', 'level:id,name',
            'primarySupervisor:id,first_name,last_name,photo_path,email,phone,position_id', 'primarySupervisor.position:id,title',
            'secondarySupervisor:id,first_name,last_name,photo_path', 'user', 'exitRecord.processor:id,name', 'scheduledExit',
            'nextOfKin', 'dependants', 'qualifications', 'benefits:id,name', 'successorRecord',
        ]);
        $isSelf = $viewer->employee?->id === $e->id;

        $data = array_merge(self::summary($e), [
            'other_names' => $e->other_names,
            'date_of_birth' => $e->date_of_birth?->toDateString(),
            'about' => $e->about,
            'cover_url' => $e->cover_path ? Storage::disk('public')->url($e->cover_path) : null,
            'company' => Company::whereKey($e->company_id)->first(['id', 'name', 'code'])?->only(['id', 'name', 'code']),
            'branch_id' => $e->branch_id,
            'department_id' => $e->department_id,
            'position_id' => $e->position_id,
            'employment_level_id' => $e->employment_level_id,
            'level' => $e->level?->only(['id', 'name']),
            'primary_supervisor_id' => $e->primary_supervisor_id,
            'secondary_supervisor_id' => $e->secondary_supervisor_id,
            'primary_supervisor' => $e->primarySupervisor ? [
                'id' => $e->primarySupervisor->id, 'full_name' => $e->primarySupervisor->full_name,
                'photo_url' => $e->primarySupervisor->photo_url, 'email' => $e->primarySupervisor->email,
                'phone' => $e->primarySupervisor->phone, 'position' => $e->primarySupervisor->position?->title,
            ] : null,
            'secondary_supervisor' => $e->secondarySupervisor ? ['id' => $e->secondarySupervisor->id, 'full_name' => $e->secondarySupervisor->full_name, 'photo_url' => $e->secondarySupervisor->photo_url] : null,
            'probation_months' => $e->probation_months,
            'has_medical_cover' => $e->has_medical_cover,
            'direct_reports_count' => Employee::withoutGlobalScope('company')->where('primary_supervisor_id', $e->id)->where('status', 'active')->count(),
            'benefits' => $e->benefits->map->only(['id', 'name'])->values(),
            'benefit_ids' => $e->benefits->pluck('id'),
            'qualifications' => $e->qualifications->map->only(['id', 'level', 'title', 'institution', 'grade', 'year_completed'])->values(),
            'exit' => $e->exitRecord ? self::exit($e->exitRecord) : null,
            'previous_record' => self::linked($e->previous_employee_id ? Employee::withoutGlobalScope('company')->find($e->previous_employee_id) : null),
            'next_record' => self::linked($e->successorRecord),
            'profile_completeness' => $e->profileCompleteness(),
            'created_at' => $e->created_at,
            'updated_at' => $e->updated_at,
        ]);

        if ($sensitive) {
            $data += [
                'national_id' => $e->national_id, 'kra_pin' => $e->kra_pin, 'nssf_number' => $e->nssf_number,
                'shif_number' => $e->shif_number, 'helb_number' => $e->helb_number,
                'next_of_kin' => $e->nextOfKin->map->only(['id', 'name', 'relationship', 'phone', 'email', 'address'])->values(),
                'dependants' => $e->dependants->map(fn ($d) => [
                    'id' => $d->id, 'name' => $d->name, 'relationship' => $d->relationship, 'phone' => $d->phone, 'email' => $d->email,
                    'date_of_birth' => $d->date_of_birth?->toDateString(), 'age' => $d->date_of_birth?->age,
                ])->values(),
            ];
        }

        $data['abilities'] = [
            'view_sensitive' => $sensitive,
            'update' => $viewer->can('employees.update') && $e->status !== 'transferred',
            'exit' => $viewer->can('employees.exit') && in_array($e->status, ['active', 'suspended'], true) && ! $e->scheduledExit,
            'reinstate' => $viewer->can('employees.exit') && $e->status === 'exited' && ! $e->successorRecord,
            'transfer' => $viewer->can('employees.transfer') && in_array($e->status, ['active', 'suspended'], true),
            'manage_account' => $viewer->can('employees.update') && $e->status === 'active',
            'upload_media' => $viewer->can('employees.update') || $isSelf,
            'history' => $viewer->can('employees.view') || $isSelf,
            'is_self' => $isSelf,
        ];

        return $data;
    }

    public static function account(?User $user, ?int $userId): ?array
    {
        if (! $userId) {
            return null;
        }
        if (! $user) {
            return ['id' => $userId];
        }

        return [
            'id' => $user->id,
            'is_active' => $user->is_active,
            'activated' => $user->activated_at !== null,
            'activated_at' => $user->activated_at,
            'last_login_at' => $user->last_login_at,
            'status' => ! $user->is_active ? 'disabled' : ($user->activated_at ? 'active' : 'pending'),
        ];
    }

    public static function exit(EmployeeExit $x): array
    {
        return [
            'id' => $x->id,
            'exit_date' => $x->exit_date?->toDateString(),
            'reason' => $x->reason,
            'status' => $x->status,
            'reason_label' => EmployeeExit::REASONS[$x->reason] ?? $x->reason,
            'comments' => $x->comments,
            'processed_by' => $x->relationLoaded('processor') ? $x->processor?->name : null,
            'created_at' => $x->created_at,
        ];
    }

    private static function linked(?Employee $e): ?array
    {
        if (! $e) {
            return null;
        }

        return [
            'id' => $e->id,
            'employee_number' => $e->employee_number,
            'status' => $e->status,
            'company_id' => $e->company_id,
            'company' => Company::withTrashed()->whereKey($e->company_id)->value('name'),
            'date_of_joining' => $e->date_of_joining?->toDateString(),
        ];
    }

    public static function yearsOfService(Employee $e): ?float
    {
        if (! $e->date_of_joining) {
            return null;
        }
        $end = $e->status === 'exited' && $e->relationLoaded('exitRecord') && $e->exitRecord ? $e->exitRecord->exit_date : now();

        return round(max(0, $e->date_of_joining->diffInDays($end)) / 365.25, 1);
    }

    public static function meta(?int $companyId = null): array
    {
        $companyId = $companyId ?? Tenancy::companyId();
        $scope = fn ($q) => $companyId
            ? $q->withoutGlobalScope('company')->where('company_id', $companyId)
            : $q;

        return [
            'branches'    => $scope(\App\Models\Branch::query())->orderBy('name')->get(['id', 'name']),
            'departments' => $scope(\App\Models\Department::query())->orderBy('name')->get(['id', 'name']),
            'positions'   => $scope(\App\Models\Position::query())->orderBy('title')->get(['id', 'title', 'is_supervisor', 'employment_level_id']),
            'levels'      => $scope(\App\Models\EmploymentLevel::query())->orderBy('name')->get(['id', 'name', 'probation_months']),
            'benefits'    => $scope(\App\Models\Benefit::query())->orderBy('name')->get(['id', 'name']),
            'supervisors' => $scope(Employee::query())->active()
                ->where(fn ($q) => $q->whereHas('position', fn ($p) => $p->where('is_supervisor', true))->orWhereHas('directReports'))
                ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'photo_path'])
                ->map(fn ($e) => ['id' => $e->id, 'full_name' => $e->full_name, 'photo_url' => $e->photo_url]),
            'relationships'          => EmployeeNextOfKin::RELATIONSHIPS,
            'dependant_relationships' => ['spouse', 'child', 'other'],
            'exit_reasons'           => collect(EmployeeExit::REASONS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'contract_types'         => ['permanent', 'contract', 'casual', 'intern'],
            'statuses'               => ['active', 'suspended', 'exited', 'transferred'],
            'genders'                => ['male', 'female', 'other'],
            'marital_statuses'       => ['single', 'married', 'divorced', 'widowed'],
            'qualification_levels'   => ['PhD', 'Masters', 'Degree', 'Higher Diploma', 'Diploma', 'Certificate', 'Professional', 'KCSE', 'Other'],
            'age_brackets' => [
                ['value' => 'under_25', 'label' => 'Under 25'], ['value' => '25_34', 'label' => '25–34'],
                ['value' => '35_44', 'label' => '35–44'], ['value' => '45_54', 'label' => '45–54'], ['value' => '55_plus', 'label' => '55+'],
            ],
        ];
    }
}
