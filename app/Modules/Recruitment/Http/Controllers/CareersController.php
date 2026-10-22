<?php

namespace App\Modules\Recruitment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Posting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public careers page (no login): open postings and applications with a CV upload.
 * Only published postings whose closing date has not passed are visible or accept applications.
 */
class CareersController extends Controller
{
    /** GET /api/careers?company=<code or id>&search= */
    public function index(Request $request): JsonResponse
    {
        $company = $request->filled('company') ? $this->company((string) $request->query('company')) : null;
        abort_if($request->filled('company') && ! $company, 404, 'Company not found.');

        $postings = $this->open()
            ->when($company, fn ($q) => $q->where('company_id', $company->id))
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($w) => $w->where('title', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%")))
            ->with(['department' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'), 'company:id,name,code,logo_path,brand_color'])
            ->latest()->limit(200)->get();

        return response()->json([
            'company' => $company ? $this->presentCompany($company) : null,
            'data' => $postings->map(fn (Posting $p) => $this->present($p))->values(),
        ]);
    }

    /** GET /api/careers/postings/{slug} */
    public function show(string $slug): JsonResponse
    {
        $posting = $this->open()->where('slug', $slug)
            ->with(['department' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'name'), 'company:id,name,code,logo_path,brand_color'])
            ->firstOrFail();

        return response()->json(['data' => $this->present($posting, true)]);
    }

    /** POST /api/careers/postings/{slug}/apply (multipart: name, email, phone, cover_letter, cv) */
    public function apply(Request $request, string $slug): JsonResponse
    {
        $posting = $this->open()->where('slug', $slug)->firstOrFail();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ]{9,20}$/'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'website' => ['nullable', 'max:0'], // honeypot: real people leave this hidden field empty
        ], ['cv.required' => 'Attach your CV.', 'cv.mimes' => 'Upload your CV as a PDF or Word document.', 'cv.max' => 'The CV may not be larger than 5 MB.']);

        $email = strtolower(trim($data['email']));
        abort_if(Applicant::withoutGlobalScopes()->where('posting_id', $posting->id)->where('email', $email)->exists(), 422,
            'You have already applied for this position.');

        $path = $request->file('cv')->store('recruitment/cvs/'.$posting->company_id, 'local');
        $applicant = new Applicant([
            'posting_id' => $posting->id,
            'name' => strip_tags(trim($data['name'])),
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'cover_letter' => isset($data['cover_letter']) ? strip_tags($data['cover_letter']) : null,
            'cv_path' => $path,
            'source' => 'Careers page',
            'stage' => 'applied',
            'applied_at' => now(),
        ]);
        $applicant->company_id = $posting->company_id;
        $applicant->save();

        return response()->json(['message' => 'Thank you. Your application has been received.'], 201);
    }

    private function open(): Builder
    {
        return Posting::withoutGlobalScopes()->whereNull('deleted_at')->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('closing_date')->orWhereDate('closing_date', '>=', today()));
    }

    private function company(string $key): ?Company
    {
        return Company::query()->where('is_active', true)
            ->where(fn ($q) => ctype_digit($key) ? $q->whereKey((int) $key) : $q->where('code', Str::upper($key))->orWhere('code', $key))
            ->first();
    }

    private function presentCompany(Company $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'code' => $c->code, 'logo_url' => $c->logo_url, 'brand_color' => $c->brand_color];
    }

    private function present(Posting $p, bool $full = false): array
    {
        return [
            'slug' => $p->slug,
            'title' => $p->title,
            'department' => $p->department?->name,
            'location' => $p->location,
            'closing_date' => $p->closing_date?->toDateString(),
            'posted_at' => $p->created_at?->toDateString(),
            'company' => $p->company ? $this->presentCompany($p->company) : null,
            'summary' => Str::limit(strip_tags((string) $p->description), 220),
        ] + ($full ? ['description' => $p->description, 'requirements' => $p->requirements] : []);
    }
}
