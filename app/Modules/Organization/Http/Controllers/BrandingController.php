<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Organization\Support\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Logo, brand colour and localisation for the current company (permission settings.manage). */
class BrandingController extends Controller
{
    public const TIMEZONES = ['Africa/Nairobi', 'Africa/Kampala', 'Africa/Dar_es_Salaam', 'Africa/Kigali', 'Africa/Lagos', 'Africa/Johannesburg', 'Europe/London', 'UTC'];

    public const DATE_FORMATS = ['d/m/Y', 'm/d/Y', 'Y-m-d', 'd M Y'];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload(CompanyContext::current($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $company = CompanyContext::current($request);
        $data = $request->validate([
            'brand_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['required', 'string', 'in:'.implode(',', self::TIMEZONES)],
            'currency' => ['required', 'string', 'size:3', 'alpha'],
            'date_format' => ['required', 'string', 'in:'.implode(',', self::DATE_FORMATS)],
            'fy_start' => ['required', 'string', 'regex:/^(0[1-9]|[12][0-9]|3[01])-(0[1-9]|1[0-2])$/'],
        ], [
            'brand_color.regex' => 'Use a hex colour such as #4f46e5.',
            'fy_start.regex' => 'Use DD-MM, e.g. 01-07 for 1 July.',
        ]);

        $before = $this->payload($company);
        $company->brand_color = strtolower($data['brand_color']);
        $company->settings = array_replace($company->settings ?? [], [
            'timezone' => $data['timezone'], 'currency' => strtoupper($data['currency']),
            'date_format' => $data['date_format'], 'fy_start' => $data['fy_start'],
        ]);
        $company->save();
        $after = $this->payload($company);

        $changed = array_keys(array_filter($data, fn ($v, $k) => ($before[$k] ?? null) != ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            activity('settings')->causedBy($request->user())->performedOn($company)->event('updated')
                ->withProperties(['old' => array_intersect_key($before, array_flip($changed)), 'attributes' => array_intersect_key($after, array_flip($changed))])
                ->log("Branding updated for {$company->name}: ".implode(', ', $changed));
        }

        return response()->json(['message' => 'Branding saved.', 'data' => $after]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $company = CompanyContext::current($request);
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
        activity('settings')->causedBy($request->user())->performedOn($company)->log("Company \"{$company->name}\" logo changed");

        return response()->json(['message' => 'Logo uploaded.', 'logo_url' => $company->logo_url]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $company = CompanyContext::current($request);
        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->update(['logo_path' => null]);
            activity('settings')->causedBy($request->user())->performedOn($company)->log("Company \"{$company->name}\" logo removed");
        }

        return response()->json(['message' => 'Logo removed.', 'logo_url' => null]);
    }

    private function payload(Company $company): array
    {
        return [
            'company' => ['id' => $company->id, 'name' => $company->name],
            'logo_url' => $company->logo_url,
            'brand_color' => $company->brand_color ?: '#4f46e5',
            'timezone' => $company->setting('timezone', config('app.timezone', 'Africa/Nairobi')),
            'currency' => $company->setting('currency', 'KES'),
            'date_format' => $company->setting('date_format', 'd/m/Y'),
            'fy_start' => $company->setting('fy_start', '01-01'),
            'options' => ['timezones' => self::TIMEZONES, 'date_formats' => self::DATE_FORMATS],
        ];
    }
}
