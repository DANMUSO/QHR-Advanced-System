<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Modules\Employees\Support\EmployeeRules;
use App\Modules\Organization\Support\CompanyContext;
use App\Notifications\ActivateAccount;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Company::query()->orderBy('name')
            ->withCount([
                'employees' => fn (Builder $q) => $q->withoutGlobalScope('company')->where('status', 'active'),
                'branches' => fn (Builder $q) => $q->withoutGlobalScope('company'),
                'departments' => fn (Builder $q) => $q->withoutGlobalScope('company'),
            ])
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")));

        if (! $this->managesAll($request)) {
            $query->whereKey($request->user()->company_id);
        }

        $userCounts = User::query()->select('company_id', DB::raw('count(*) as c'))->groupBy('company_id')->pluck('c', 'company_id');

        return response()->json(['data' => $query->get()->each(fn ($c) => $c->setAttribute('users_count', (int) ($userCounts[$c->id] ?? 0)))->map(fn ($c) => $this->present($c))]);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $company->loadCount([
            'employees' => fn (Builder $q) => $q->withoutGlobalScope('company')->where('status', 'active'),
            'branches' => fn (Builder $q) => $q->withoutGlobalScope('company'),
            'departments' => fn (Builder $q) => $q->withoutGlobalScope('company'),
        ]);
        $company->setAttribute('users_count', User::where('company_id', $company->id)->count());
        $company->setAttribute('positions_count', DB::table('positions')->where('company_id', $company->id)->count());
        $company->setAttribute('contacts', DB::table('contact_persons')->where('company_id', $company->id)->orderBy('name')->get(['id', 'name', 'email', 'phone', 'role']));
        $company->setAttribute('hr_contact', [
            'name' => $company->setting('hr_contact_name'),
            'email' => $company->setting('hr_contact_email'),
            'phone' => $company->setting('hr_contact_phone'),
        ]);
        $company->setAttribute('can_manage_all', $this->managesAll($request));

        return response()->json(['data' => $this->present($company)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('companies', 'code')],
            'admin_name' => ['nullable', 'required_with:admin_email', 'string', 'max:120'],
            'admin_email' => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
        ], $this->messages());

        $company = DB::transaction(function () use ($data, $request) {
            $company = Company::create(collect($data)->except(['admin_name', 'admin_email'])->all() + ['is_active' => true]);

            if (! empty($data['admin_email'])) {
                $admin = User::create(['company_id' => $company->id, 'name' => $data['admin_name'], 'email' => $data['admin_email'], 'is_active' => true]);
                $admin->assignRole('hr-admin');
                $admin->notify(new ActivateAccount(Password::broker('activations')->createToken($admin)));
            }

            activity('organization')->causedBy($request->user())->performedOn($company)->event('created')
                ->withProperties(['attributes' => $data])->log("Company \"{$company->name}\" created");

            return $company;
        });

        $message = 'Company created.'.(! empty($data['admin_email']) ? ' An activation email was sent to the company admin.' : '');

        return response()->json(['message' => $message, 'data' => $this->present($company->fresh())], 201);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);

        $rules = $this->rules();
        if ($this->managesAll($request)) {
            $rules['code'] = ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('companies', 'code')->ignore($company->id)];
        }
        $data = $request->validate($rules, $this->messages());

        $old = array_intersect_key($company->getAttributes(), $data);
        $company->fill($data)->save();

        activity('organization')->causedBy($request->user())->performedOn($company)->event('updated')
            ->withProperties(['old' => $old, 'attributes' => $company->getChanges()])->log("Company \"{$company->name}\" profile updated");

        return response()->json(['message' => 'Company profile saved.', 'data' => $this->present($company->fresh())]);
    }

    public function uploadLogo(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        $request->validate(['logo' => ['required', 'file', 'mimes:png,jpg,jpeg', 'mimetypes:image/png,image/jpeg', 'max:2048']], [
            'logo.max' => 'The logo must not be larger than 2 MB.',
            'logo.mimes' => 'The logo must be a PNG or JPEG image.',
            'logo.mimetypes' => 'The logo must be a PNG or JPEG image.',
        ]);

        $old = $company->logo_path;
        $company->logo_path = $request->file('logo')->store('organization/logos', 'public');
        $company->save();
        if ($old && $old !== $company->logo_path) {
            Storage::disk('public')->delete($old);
        }

        activity('organization')->causedBy($request->user())->performedOn($company)->log("Company \"{$company->name}\" logo changed");

        return response()->json(['message' => 'Logo uploaded.', 'logo_url' => $company->logo_url, 'data' => $this->present($company)]);
    }

    public function deleteLogo(Request $request, Company $company): JsonResponse
    {
        $this->authorizeCompany($request, $company);
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
            activity('organization')->causedBy($request->user())->performedOn($company)->log("Company \"{$company->name}\" logo removed");
        }

        return response()->json(['message' => 'Logo removed.', 'data' => $this->present($company)]);
    }

    public function toggleActive(Request $request, Company $company): JsonResponse
    {
        abort_if($company->is_active && $company->id === $request->user()->company_id, 422, 'You cannot deactivate your own company.');
        $company->update(['is_active' => ! $company->is_active]);

        $state = $company->is_active ? 'activated' : 'deactivated';
        activity('organization')->causedBy($request->user())->performedOn($company)->log("Company \"{$company->name}\" {$state}");

        return response()->json(['message' => "Company {$state}.", 'data' => $this->present($company)]);
    }

    public function destroy(Request $request, Company $company): JsonResponse
    {
        $employees = DB::table('employees')->where('company_id', $company->id)->whereNull('deleted_at')->count();
        $users = User::where('company_id', $company->id)->count();
        if ($employees || $users) {
            return response()->json([
                'message' => "This company still has {$employees} employee record(s) and {$users} user account(s). Deactivate it instead.",
            ], 422);
        }

        $company->delete();
        activity('organization')->causedBy($request->user())->performedOn($company)->event('deleted')->log("Company \"{$company->name}\" deleted");

        return response()->json(['message' => 'Company deleted.']);
    }

    // ----------------------------------------------------------------------------------------

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:'.EmployeeRules::PHONE_REGEX],
            'location' => ['nullable', 'string', 'max:190'],
            'address' => ['nullable', 'string', 'max:190'],
            'tax_pin' => ['nullable', 'string', 'regex:'.EmployeeRules::KRA_REGEX],
            'incorporation_number' => ['nullable', 'string', 'max:50'],
            'incorporation_date' => ['nullable', 'date', 'before_or_equal:today'],
            'brand_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    private function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid phone number, e.g. 0712345678 or +254712345678.',
            'tax_pin.regex' => 'KRA PIN must look like A012345678Z.',
        ];
    }

    /** Company as JSON: calendar date without timezone shift; raw settings are served by the settings endpoint. */
    private function present(Company $company): array
    {
        $out = collect($company->toArray())->except(['settings'])->all();
        $out['incorporation_date'] = $company->incorporation_date?->toDateString();

        return $out;
    }

    private function managesAll(Request $request): bool
    {
        return Tenancy::isGlobal() || $request->user()->can('companies.manage');
    }

    private function authorizeCompany(Request $request, Company $company): void
    {
        abort_unless($this->managesAll($request) || CompanyContext::canAccess($request, $company->id), 403, 'You can only manage your own company.');
    }
}
