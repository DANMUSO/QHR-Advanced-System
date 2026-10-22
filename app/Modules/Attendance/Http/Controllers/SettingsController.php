<?php

namespace App\Modules\Attendance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Attendance\Support\AttendanceRules;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Company attendance rules (stored in companies.settings; only this module's keys are touched). */
class SettingsController extends Controller
{
    private function company(Request $request): Company
    {
        $id = Tenancy::companyId() ?? ($request->integer('company_id') ?: null);
        abort_unless($id, 422, 'Select a company to edit its attendance rules.');

        return Company::findOrFail($id);
    }

    public function show(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json(['company' => ['id' => $company->id, 'name' => $company->name], 'data' => AttendanceRules::companyValues($company), 'defaults' => AttendanceRules::DEFAULTS]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'late_grace_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'break_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'hours_per_day' => ['required', 'numeric', 'min:1', 'max:24'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'web_clock_enabled' => ['required', 'boolean'],
            'overtime_min_minutes' => ['required', 'integer', 'min:0', 'max:480'],
            'overtime_multiplier_normal' => ['required', 'numeric', 'min:1', 'max:5'],
            'overtime_multiplier_holiday' => ['required', 'numeric', 'min:1', 'max:5'],
        ]);
        $data['working_days'] = array_values(array_unique(array_map('intval', $data['working_days'])));
        sort($data['working_days']);
        $data['web_clock_enabled'] = (bool) $data['web_clock_enabled'];

        $before = array_intersect_key($company->settings ?? [], $data);
        $company->settings = array_replace($company->settings ?? [], $data);
        $company->save();
        activity('attendance')->performedOn($company)->causedBy($request->user())->withProperties(['old' => $before, 'new' => $data])->log('Attendance rules updated');

        return response()->json(['message' => 'Attendance rules saved. They apply to days computed from now on — recompute past days if needed.', 'data' => AttendanceRules::companyValues($company)]);
    }
}
