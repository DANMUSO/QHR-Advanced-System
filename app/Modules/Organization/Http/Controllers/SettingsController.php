<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Organization\Support\CompanyContext;
use App\Modules\Organization\Support\SettingsSchema;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/** Per-company rules stored in companies.settings (JSON). */
class SettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $company = CompanyContext::current($request);

        return response()->json($this->payload($company));
    }

    public function update(Request $request): JsonResponse
    {
        $company = CompanyContext::current($request);
        $roles = Role::pluck('name')->all();

        $data = SettingsSchema::normalize($request->validate(SettingsSchema::rules($roles), [
            'work_end.after' => 'Work end time must be after the start time.',
            'medical_monthly_limit.lte' => 'The monthly limit cannot exceed the annual limit.',
            'require_two_factor_roles.*.in' => 'Unknown role selected.',
            'hr_contact_phone.regex' => 'Enter a valid phone number, e.g. 0712345678 or +254712345678.',
        ]));

        $before = SettingsSchema::resolve($company);
        // Merge so keys owned by other modules are preserved.
        $company->settings = array_replace($company->settings ?? [], $data);
        $company->save();
        $after = SettingsSchema::resolve($company);

        $changed = array_keys(array_filter($after, fn ($v, $k) => $v != ($before[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            activity('settings')->causedBy($request->user())->performedOn($company)->event('updated')
                ->withProperties([
                    'old' => array_intersect_key($before, array_flip($changed)),
                    'attributes' => array_intersect_key($after, array_flip($changed)),
                ])
                ->log("Company settings updated for {$company->name}: ".implode(', ', $changed));
        }

        return response()->json(['message' => 'Settings saved.'] + $this->payload($company->fresh()));
    }

    private function payload(Company $company): array
    {
        return [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'settings' => SettingsSchema::resolve($company),
            'defaults' => SettingsSchema::defaults(),
            'roles' => Role::orderBy('name')->pluck('name'),
            'companies' => Tenancy::isGlobal() ? Company::orderBy('name')->get(['id', 'name']) : null,
        ];
    }
}
