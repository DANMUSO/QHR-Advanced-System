<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Benefit;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ContactPerson;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Holiday;
use App\Models\Position;
use App\Modules\Employees\Support\EmployeeRules;
use App\Modules\Organization\Support\CompanyContext;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD for the per-company organisation structure:
 * branches, departments, positions, employment levels, benefits, contact persons and holidays.
 */
class StructureController extends Controller
{
    public const TYPES = ['branches', 'departments', 'positions', 'levels', 'benefits', 'contacts', 'holidays'];

    private const MODELS = [
        'branches' => Branch::class,
        'departments' => Department::class,
        'positions' => Position::class,
        'levels' => EmploymentLevel::class,
        'benefits' => Benefit::class,
        'contacts' => ContactPerson::class,
        'holidays' => Holiday::class,
    ];

    private const LABELS = [
        'branches' => 'Branch', 'departments' => 'Department', 'positions' => 'Position', 'levels' => 'Employment level',
        'benefits' => 'Benefit', 'contacts' => 'Contact person', 'holidays' => 'Holiday',
    ];

    /** Counts for the organisation page tabs and company profile. */
    public function summary(Request $request): JsonResponse
    {
        $holidays = $this->query($request, 'holidays');

        return response()->json([
            'branches' => Branch::count(),
            'departments' => Department::count(),
            'positions' => Position::count(),
            'levels' => EmploymentLevel::count(),
            'benefits' => Benefit::count(),
            'contacts' => ContactPerson::count(),
            'holidays' => $holidays->count(),
            'employees' => Employee::where('status', 'active')->count(),
        ]);
    }

    public function index(Request $request, string $type): JsonResponse
    {
        $query = $this->query($request, $type)->with('company:id,name');
        $search = trim((string) $request->query('search'));

        match ($type) {
            'branches' => $query->with('head:id,first_name,last_name')->withCount(['employees' => fn ($q) => $q->where('status', 'active')])->orderBy('name'),
            'departments' => $query->with('head:id,first_name,last_name')->withCount(['employees' => fn ($q) => $q->where('status', 'active')])->orderBy('name'),
            'positions' => $query->with('level:id,name')->withCount(['employees' => fn ($q) => $q->where('status', 'active')])->orderBy('title'),
            'levels' => $query->withCount('positions')->orderBy('name'),
            'benefits' => $query->orderBy('name'),
            'contacts' => $query->orderBy('name'),
            'holidays' => $query->when($request->integer('year'), fn ($q, $y) => $q->where(fn ($w) => $w->whereYear('date', $y)->orWhere('recurring', true)))
                ->orderByRaw('MONTH(date), DAY(date)'),
        };

        if ($search !== '') {
            $column = $type === 'positions' ? 'title' : 'name';
            $query->where($column, 'like', "%{$search}%");
        }

        $rows = $query->get();

        if ($type === 'levels') {
            $counts = Employee::query()->whereIn('employment_level_id', $rows->pluck('id'))->where('status', 'active')
                ->select('employment_level_id', DB::raw('count(*) as c'))->groupBy('employment_level_id')->pluck('c', 'employment_level_id');
            $rows->each(fn ($r) => $r->setAttribute('employees_count', (int) ($counts[$r->id] ?? 0)));
        }
        if ($type === 'benefits') {
            $counts = DB::table('benefit_employee')->whereIn('benefit_id', $rows->pluck('id'))
                ->select('benefit_id', DB::raw('count(*) as c'))->groupBy('benefit_id')->pluck('c', 'benefit_id');
            $rows->each(fn ($r) => $r->setAttribute('employees_count', (int) ($counts[$r->id] ?? 0)));
        }
        if (in_array($type, ['branches', 'departments'], true)) {
            $rows->each(fn ($r) => $r->setAttribute('head_name', $r->head?->full_name));
        }

        return response()->json(['data' => $rows->map(fn ($r) => $this->present($r))->values()]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $class = self::MODELS[$type];
        $global = $type === 'holidays' && $request->boolean('is_global');
        abort_if($global && ! Tenancy::isGlobal(), 403, 'Only a super admin can create holidays for all companies.');

        $companyId = $global ? null : CompanyContext::forWrite($request);
        $data = $this->validated($request, $type, $companyId);

        /** @var Model $model */
        $model = new $class($data);
        $model->company_id = $companyId;
        $model->save();

        activity('organization')->causedBy($request->user())->performedOn($model)
            ->withProperties(['attributes' => $data])->event('created')
            ->log(self::LABELS[$type].' "'.$this->title($model).'" created');

        return response()->json(['message' => self::LABELS[$type].' created.', 'data' => $this->present($model->fresh())], 201);
    }

    public function update(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->findForWrite($request, $type, $id);
        $data = $this->validated($request, $type, $model->company_id, $model);

        $old = array_intersect_key($model->getAttributes(), $data);
        $model->fill($data)->save();

        activity('organization')->causedBy($request->user())->performedOn($model)
            ->withProperties(['old' => $old, 'attributes' => $model->getChanges()])->event('updated')
            ->log(self::LABELS[$type].' "'.$this->title($model).'" updated');

        return response()->json(['message' => self::LABELS[$type].' updated.', 'data' => $this->present($model->fresh())]);
    }

    public function destroy(Request $request, string $type, int $id): JsonResponse
    {
        $model = $this->findForWrite($request, $type, $id);

        if ($reason = $this->inUse($type, $model)) {
            return response()->json(['message' => $reason], 422);
        }

        $title = $this->title($model);
        $model->delete();

        activity('organization')->causedBy($request->user())->performedOn($model)
            ->withProperties(['old' => $model->getAttributes()])->event('deleted')
            ->log(self::LABELS[$type]." \"{$title}\" deleted");

        return response()->json(['message' => self::LABELS[$type].' deleted.']);
    }

    // ----------------------------------------------------------------------------------------

    private function query(Request $request, string $type): Builder
    {
        if ($type !== 'holidays') {
            return (self::MODELS[$type])::query(); // BelongsToCompany scopes it
        }

        // Holidays: company-specific plus global (company_id null) ones.
        if (Tenancy::isGlobal()) {
            $scoped = Tenancy::companyId() ?: ($request->integer('company_id') ?: null);

            return Holiday::query()->when($scoped, fn ($q) => $q->forCompany($scoped));
        }

        return Holiday::query()->forCompany($request->user()->company_id);
    }

    private function findForWrite(Request $request, string $type, int $id): Model
    {
        $model = $this->query($request, $type)->findOrFail($id);

        if ($type === 'holidays' && $model->company_id === null) {
            abort_unless(Tenancy::isGlobal(), 403, 'This holiday applies to all companies and can only be changed by a super admin.');
        } else {
            abort_unless(CompanyContext::canAccess($request, $model->company_id), 403);
        }

        return $model;
    }

    private function validated(Request $request, string $type, ?int $companyId, ?Model $model = null): array
    {
        $inCompany = fn (string $table) => Rule::exists($table, 'id')->where('company_id', $companyId);
        $unique = fn (string $table, string $column = 'name') => Rule::unique($table, $column)
            ->where(fn ($q) => $companyId === null ? $q->whereNull('company_id') : $q->where('company_id', $companyId))
            ->ignore($model?->getKey());

        $rules = match ($type) {
            'branches' => [
                'name' => ['required', 'string', 'max:120', $unique('branches')],
                'location' => ['nullable', 'string', 'max:190'],
                'address' => ['nullable', 'string', 'max:190'],
                'email' => ['nullable', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:30', 'regex:'.EmployeeRules::PHONE_REGEX],
                'head_employee_id' => ['nullable', 'integer', $inCompany('employees')],
            ],
            'departments' => [
                'name' => ['required', 'string', 'max:120', $unique('departments')],
                'email' => ['nullable', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:30', 'regex:'.EmployeeRules::PHONE_REGEX],
                'head_employee_id' => ['nullable', 'integer', $inCompany('employees')],
            ],
            'positions' => [
                'title' => ['required', 'string', 'max:120', $unique('positions', 'title')],
                'employment_level_id' => ['required', 'integer', $inCompany('employment_levels')],
                'is_supervisor' => ['boolean'],
            ],
            'levels' => [
                'name' => ['required', 'string', 'max:120', $unique('employment_levels')],
                'probation_months' => ['required', 'integer', 'min:0', 'max:24'],
                'probation_review_days' => ['required', 'integer', 'min:0', 'max:365'],
            ],
            'benefits' => [
                'name' => ['required', 'string', 'max:120', $unique('benefits')],
                'description' => ['nullable', 'string', 'max:255'],
            ],
            'contacts' => [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['nullable', 'email', 'max:190'],
                'phone' => ['nullable', 'string', 'max:30', 'regex:'.EmployeeRules::PHONE_REGEX],
                'role' => ['nullable', 'string', 'max:120'],
            ],
            'holidays' => [
                'name' => ['required', 'string', 'max:120'],
                'date' => ['required', 'date'],
                'recurring' => ['boolean'],
            ],
        };

        $data = $request->validate($rules, [
            'head_employee_id.exists' => 'Choose an employee from this company.',
            'employment_level_id.exists' => 'Choose a level from this company.',
            'employment_level_id.required' => 'Choose the employment level for this position (add one under Levels first if none exist).',
            'name.unique' => 'A record with this name already exists.',
            'title.unique' => 'A position with this title already exists.',
            'phone.regex' => 'Enter a valid phone number, e.g. 0712345678 or +254712345678.',
        ]);

        foreach (['is_supervisor', 'recurring'] as $flag) {
            if (array_key_exists($flag, $rules)) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        return $data;
    }

    /** Human-readable reason a record cannot be deleted, or null. */
    private function inUse(string $type, Model $model): ?string
    {
        $employees = fn (string $column) => Employee::withoutGlobalScope('company')->withTrashed()
            ->where($column, $model->getKey())->whereIn('status', ['active', 'suspended'])->count();

        $n = match ($type) {
            'branches' => $employees('branch_id'),
            'departments' => $employees('department_id'),
            'positions' => $employees('position_id'),
            'levels' => $employees('employment_level_id'),
            'benefits' => DB::table('benefit_employee')->where('benefit_id', $model->getKey())->count(),
            default => 0,
        };
        if ($n > 0) {
            $what = strtolower(self::LABELS[$type]);

            return "This {$what} is assigned to {$n} employee".($n === 1 ? '' : 's').'. Reassign them before deleting it.';
        }

        if ($type === 'levels' && ($p = Position::withoutGlobalScope('company')->where('employment_level_id', $model->getKey())->count())) {
            return "This level is used by {$p} position".($p === 1 ? '' : 's').'. Change those positions first.';
        }

        return null;
    }

    /** Serialises a record, keeping calendar dates as plain Y-m-d (no timezone shift). */
    private function present(Model $model): array
    {
        $out = $model->toArray();
        if ($model instanceof Holiday) {
            $out['date'] = $model->date?->toDateString();
            $out['is_global'] = $model->company_id === null;
        }

        return $out;
    }

    private function title(Model $model): string
    {
        return (string) ($model->getAttribute('name') ?? $model->getAttribute('title') ?? $model->getKey());
    }
}
