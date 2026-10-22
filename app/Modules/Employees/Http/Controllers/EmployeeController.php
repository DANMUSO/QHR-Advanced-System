<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Employees\Models\ProfileChangeRequest;
use App\Modules\Employees\Services\SelfServiceRequestService;
use App\Modules\Employees\Services\EmployeeService;
use App\Modules\Employees\Support\EmployeeAccess;
use App\Modules\Employees\Support\EmployeePresenter;
use App\Modules\Employees\Support\EmployeeQuery;
use App\Modules\Employees\Support\EmployeeRules;
use App\Modules\Recruitment\Services\ApplicantConversion;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $service) {}

    public function meta(Request $request): JsonResponse
    {
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);

        return response()->json(EmployeePresenter::meta($companyId));
    }

    /** Directory (employees.view) or, for supervisors / ?scope=team, the user's direct reports. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $request->query('scope') === 'team' || ! $user->can('employees.view');
        abort_if($team && ! $user->employee, 403, 'You do not have access to the employee directory.');

        $query = EmployeeQuery::scopeForViewer(EmployeeQuery::build($request), $user, $team)->with('scheduledExit:id,employee_id,exit_date,status');
        if (str_contains((string) $request->query('status'), 'exited') || str_contains((string) $request->query('status'), 'transferred')) {
            $query->with(['exitRecord', 'successorRecord:id,previous_employee_id,company_id']);
        }
        // Supervisors view: include pending/rejected leave counts and direct report count
        $withLeaveCounts = $request->boolean('supervisor_role');
        if ($withLeaveCounts) {
            $query->withCount([
                'directReports as direct_reports_count' => fn ($q) => $q->where('status', 'active'),
            ]);
        }

        $perPage = min(max($request->integer('per_page', 25), 5), 100);
        $page = $query->paginate($perPage);

        // For the supervisors view, attach pending/rejected leave counts per supervisor
        if ($withLeaveCounts && class_exists(\App\Modules\Leave\Models\LeaveRequest::class)) {
            $ids = $page->pluck('id');
            $pending = \App\Modules\Leave\Models\LeaveRequest::withoutGlobalScopes()
                ->where('status', \App\Modules\Leave\Models\LeaveRequest::PENDING_SUPERVISOR)
                ->whereIn('supervisor_id', $ids)->select('supervisor_id')
                ->selectRaw('COUNT(*) as cnt')->groupBy('supervisor_id')->pluck('cnt', 'supervisor_id');
            $rejected = \App\Modules\Leave\Models\LeaveRequest::withoutGlobalScopes()
                ->where('status', \App\Modules\Leave\Models\LeaveRequest::REJECTED)
                ->whereYear('updated_at', now()->year)
                ->whereIn('supervisor_id', $ids)->select('supervisor_id')
                ->selectRaw('COUNT(*) as cnt')->groupBy('supervisor_id')->pluck('cnt', 'supervisor_id');
            $page->getCollection()->transform(function (Employee $e) use ($pending, $rejected) {
                $e->setAttribute('pending_leave_count', (int) ($pending[$e->id] ?? 0));
                $e->setAttribute('rejected_leave_count', (int) ($rejected[$e->id] ?? 0));
                return $e;
            });
        }

        return response()->json($page->through(fn (Employee $e) => EmployeePresenter::summary($e)));
    }

    /** CSV of the filtered directory (all pages). */
    public function export(Request $request): StreamedResponse
    {
        $query = EmployeeQuery::build($request)->with(['exitRecord']);
        activity('employees')->withProperties(['filters' => $request->query()])->log('Exported employee list');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Employee No', 'First name', 'Last name', 'Gender', 'Age', 'Marital status', 'Phone', 'Email',
                'Postal address', 'Residence', 'Branch', 'Department', 'Position', 'Supervisor', 'Contract type',
                'Contract end', 'Joined', 'Years of service', 'Status', 'Exit date', 'Login']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $e) {
                    $s = EmployeePresenter::summary($e);
                    fputcsv($out, [
                        $e->employee_number, $e->first_name, $e->last_name, ucfirst((string) $e->gender), $s['age'],
                        ucfirst((string) $e->marital_status), $e->phone, $e->email, $e->postal_address, $e->residence,
                        $e->branch?->name, $e->department?->name, $e->position?->title, $e->primarySupervisor?->full_name,
                        ucfirst($e->contract_type), $s['contract_end_date'], $s['date_of_joining'], $s['years_of_service'],
                        ucfirst($e->status), $e->exitRecord?->exit_date?->toDateString(),
                        $s['account']['status'] ?? 'none',
                    ]);
                }
            });
            fclose($out);
        }, 'employees-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Counts for the directory tabs. */
    public function stats(): JsonResponse
    {
        $active = fn () => Employee::query()->whereIn('status', ['active', 'suspended']);

        return response()->json([
            'active' => Employee::where('status', 'active')->count(),
            'suspended' => Employee::where('status', 'suspended')->count(),
            'exited' => Employee::where('status', 'exited')->count(),
            'transferred' => Employee::where('status', 'transferred')->count(),
            'not_activated' => $active()->whereHas('user', fn ($u) => $u->whereNull('activated_at'))->count(),
            'without_login' => $active()->whereNull('user_id')->count(),
            'without_supervisor' => $active()->whereNull('primary_supervisor_id')->count(),
            'on_probation' => $active()->where('probation_status', 'on_probation')->count(),
            'contracts_ending' => $active()->whereNotNull('contract_end_date')->whereBetween('contract_end_date', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
        ]);
    }

    public function orgChart(): JsonResponse
    {
        // Plain PHP array so references work for tree building
        $nodes = [];
        foreach (Employee::query()->where('status', 'active')
            ->with(['position:id,title,is_supervisor', 'department:id,name', 'branch:id,name'])
            ->orderBy('first_name')->get() as $e) {
            $nodes[$e->id] = [
                'id' => $e->id, 'name' => $e->full_name, 'employee_number' => $e->employee_number,
                'photo_url' => $e->photo_url, 'position' => $e->position?->title,
                'is_supervisor_position' => (bool) $e->position?->is_supervisor,
                'department_id' => $e->department_id, 'department' => $e->department?->name,
                'branch' => $e->branch?->name, 'email' => $e->email, 'phone' => $e->phone,
                'date_of_joining' => $e->date_of_joining?->toDateString(),
                'supervisor_id' => $e->primary_supervisor_id, 'children' => [],
            ];
        }

        $roots = [];
        foreach ($nodes as $id => &$node) {
            $supId = $node['supervisor_id'];
            if ($supId && isset($nodes[$supId])) {
                $nodes[$supId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return response()->json(['data' => array_values($roots)]);
    }

    public function directReports(int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);

        return response()->json(['data' => Employee::query()->where('primary_supervisor_id', $e->id)->whereIn('status', ['active', 'suspended'])
            ->with(['position:id,title'])->orderBy('first_name')->get()
            ->map(fn ($r) => ['id' => $r->id, 'full_name' => $r->full_name, 'photo_url' => $r->photo_url, 'position' => $r->position?->title, 'employee_number' => $r->employee_number])]);
    }

    public function nextNumber(Request $request): JsonResponse
    {
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        abort_unless($companyId, 422, 'Select a company first.');

        return response()->json(['employee_number' => EmployeeService::nextNumber($companyId)]);
    }

    /** Self-service: the signed-in user's own employee record. */
    public function myProfile(Request $request): JsonResponse
    {
        $e = $request->user()->employee;
        abort_unless($e, 404, 'Your account is not linked to an employee record.');

        return response()->json(['data' => $this->selfView($e, $request)]);
    }

    /**
     * Self-service edit. Plain personal fields (phone, address, residence, marital status, about, dependants, qualifications)
     * save directly; sensitive ones (national ID, KRA/NSSF/SHIF/HELB, next of kin, pay details) become a change request HR approves.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $e = $request->user()->employee;
        abort_unless($e, 404, 'Your account is not linked to an employee record.');
        $requests = app(SelfServiceRequestService::class);

        $family = Arr::where(EmployeeRules::family(requireNextOfKin: false), fn ($r, $k) => ! str_starts_with($k, 'next_of_kin'));
        $rules = EmployeeRules::personal($e->company_id, $e, self: true) + $family;
        $direct = $request->except([...ProfileChangeRequest::STATUTORY, 'next_of_kin', 'payment']);
        $validator = Validator::make($direct, $rules, EmployeeRules::messages(), EmployeeRules::attributes());
        $validator->after(fn ($v) => EmployeeRules::after($v, null));
        $data = $validator->validate();

        // Validate the sensitive changes up front so nothing is saved when they are invalid.
        $changes = $requests->diff($e, $request->all());
        if ($changes) {
            $requests->validate($e, $changes);
        }

        $this->service->update($e, $data, ['phone', 'postal_address', 'residence', 'marital_status', 'about']);
        $message = 'Profile updated.';
        if ($changes) {
            $pending = $requests->submitChange($e, $changes, $request->user(), $request->input('change_note'));
            $message = "Profile updated. Your change to {$pending->summary()} was sent to HR for approval.";
        }

        return response()->json(['data' => $this->selfView($e->fresh(), $request), 'message' => $message]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        return app(EmployeeMediaController::class)->uploadOwn($request, 'photo');
    }

    private function selfView(Employee $e, Request $request): array
    {
        $detail = EmployeePresenter::detail($e, $request->user(), true);

        // Flat labels for simple self-service screens.
        return array_merge($detail, [
            'full_name' => $e->full_name,
            'photo_url' => $e->photo_url,
            'position' => $e->position?->title,
            'department' => $e->department?->name,
            'branch' => $e->branch?->name,
            'profile_completeness' => $e->profileCompleteness(),
            // Pay details (masked) and any sensitive change still waiting for HR.
            'payment' => app(SelfServiceRequestService::class)->maskedPayment($e),
            'pending_change' => ProfileChangeRequest::where('employee_id', $e->id)->where('status', 'pending')
                ->latest('id')->first()?->only(['id', 'changes', 'note', 'created_at']),
        ]);
    }

    public function show(Request $request, int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        $user = $request->user();
        abort_unless(EmployeeAccess::canView($user, $e), 403, 'You cannot view this employee.');

        return response()->json(['data' => EmployeePresenter::detail($e, $user, EmployeeAccess::canSeeSensitive($user, $e))]);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = Tenancy::companyIdForWrite($request->integer('company_id') ?: null);
        if (! $companyId) {
            throw ValidationException::withMessages(['company_id' => 'Select the company this employee belongs to.']);
        }
        $data = $this->validateEmployee($request, $companyId, null) + ['create_login' => $request->boolean('create_login')];

        // Converting a hired applicant (Recruitment): same onboarding path, then linked back to the applicant.
        $hire = $request->validate([
            'applicant_id' => ['nullable', 'integer'],
            'gross_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        $conversion = ! empty($hire['applicant_id']) && class_exists(ApplicantConversion::class) ? app(ApplicantConversion::class) : null;
        $applicant = $conversion?->assertConvertible((int) $hire['applicant_id'], $companyId);
        $gross = isset($hire['gross_salary']) && $request->user()->can('employees.view-salary') ? (float) $hire['gross_salary'] : null;

        // Core save is atomic: the record, its children, contract and login commit together or not at all.
        $employee = DB::transaction(function () use ($data, $companyId, $conversion, $applicant, $gross, $request) {
            $employee = $this->service->create($data, $companyId);
            activity('employees')->performedOn($employee)->log($applicant ? 'Onboarded employee (from recruitment)' : 'Onboarded employee');
            $conversion?->link($applicant, $employee, $gross, $request->user());

            return $employee;
        });

        // Optional automation (appraisal enrolment, probation review, pay profile) runs after commit;
        // a failing listener is logged and never undoes or blocks the hire.
        SideEffects::dispatch(new EmployeeHired($employee));

        return response()->json([
            'data' => EmployeePresenter::detail($employee->fresh(), $request->user(), true),
            'message' => "{$employee->full_name} has been added".($data['create_login'] ? ' and an activation email sent.' : '.'),
        ], 201);
    }

    public function update(Request $request, int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        abort_if($e->status === 'transferred', 422, 'Transferred records are read-only. Edit the record in the new company.');

        $data = $this->validateEmployee($request, $e->company_id, $e);
        $this->service->update($e, $data);

        return response()->json([
            'data' => EmployeePresenter::detail($e->fresh(), $request->user(), true),
            'message' => 'Employee record updated.',
        ]);
    }

    /** Audit trail for one employee: record changes, workflow events and login account changes. */
    public function history(Request $request, int $employee): JsonResponse
    {
        $e = Employee::findOrFail($employee);
        $user = $request->user();
        abort_unless($user->can('employees.view') || $user->employee?->id === $e->id, 403);

        $query = Activity::with('causer:id,name')->latest('id')->where(function ($q) use ($e) {
            $q->where(fn ($w) => $w->where('subject_type', Employee::class)->where('subject_id', $e->id));
            if ($e->user_id) {
                $q->orWhere(fn ($w) => $w->where('subject_type', User::class)->where('subject_id', $e->user_id));
            }
        });

        return response()->json($query->paginate(20)->through(fn (Activity $a) => [
            'id' => $a->id,
            'description' => $a->description,
            'event' => $a->event,
            'subject' => class_basename((string) $a->subject_type),
            'causer' => $a->causer?->name ?? 'System',
            'properties' => $a->properties,
            'at' => $a->created_at,
        ]));
    }

    private function validateEmployee(Request $request, int $companyId, ?Employee $employee): array
    {
        $rules = EmployeeRules::personal($companyId, $employee)
            + EmployeeRules::statutory($companyId, $employee)
            + EmployeeRules::employment($companyId, $employee)
            + EmployeeRules::family(requireNextOfKin: $employee === null)
            + EmployeeRules::benefits($companyId)
            + ['create_login' => ['boolean'], 'company_id' => ['nullable', 'integer', 'exists:companies,id']];

        $validator = Validator::make($request->all(), $rules, EmployeeRules::messages(), EmployeeRules::attributes());
        $validator->after(fn ($v) => EmployeeRules::after($v, $employee));

        return $validator->validate();
    }
}
