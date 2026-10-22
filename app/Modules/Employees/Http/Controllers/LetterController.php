<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Services\LetterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LetterController extends Controller
{
    public function __construct(private LetterService $letters) {}

    public function templates(): JsonResponse
    {
        return response()->json(['data' => LetterService::templates()]);
    }

    /** GET /employees/letters?employee_id=&template=&search= */
    public function index(Request $request): JsonResponse
    {
        $query = EmployeeLetter::query()
            ->whereIn('employee_id', Employee::query()->withTrashed()->search($request->query('search'))->select('employees.id'))
            ->with(['employee' => fn ($q) => $q->withTrashed(), 'generator:id,name'])
            ->when($request->integer('employee_id'), fn ($q, $id) => $q->where('employee_id', $id))
            ->when($request->query('template'), fn ($q, $t) => $q->where('template', $t))
            ->latest('id');

        return response()->json($query->paginate(min($request->integer('per_page', 25) ?: 25, 100))->through(fn (EmployeeLetter $l) => $this->present($l)));
    }

    /** POST /employees/letters — generate; the response carries a URL to the PDF. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'template' => ['required', Rule::in(array_keys(LetterService::TEMPLATES))],
            'effective_date' => ['nullable', 'date'],
            'notes' => [Rule::requiredIf(fn () => in_array($request->input('template'), ['warning', 'no_objection'], true)), 'nullable', 'string', 'max:5000'],
        ], ['notes.required' => 'Describe the details for this letter in the notes.', 'template.required' => 'Choose a letter template.']);

        $employee = Employee::findOrFail($data['employee_id']);
        if ($data['template'] === 'certificate_of_service' && $employee->status !== 'exited') {
            abort(422, 'A certificate of service is issued to employees who have exited.');
        }
        $letter = $this->letters->generate($employee, $data['template'], $data['effective_date'] ?? null, $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $this->present($letter->load('employee', 'generator')), 'url' => $this->url($letter), 'message' => 'Letter generated.'], 201);
    }

    public function download(Request $request, int $letter): Response
    {
        $l = EmployeeLetter::withoutGlobalScope('company')->findOrFail($letter);
        $user = $request->user();
        abort_unless(($user->can('employees.view') && Employee::withTrashed()->whereKey($l->employee_id)->exists()) || $user->employee?->id === $l->employee_id, 403);

        return $this->letters->pdf($l)->stream(Str::slug($l->subject.'-'.$l->id).'.pdf');
    }

    private function url(EmployeeLetter $l): string
    {
        return url('/api/employees/letters/'.$l->id.'/download');
    }

    private function present(EmployeeLetter $l): array
    {
        return [
            'id' => $l->id,
            'reference' => $l->reference,
            'employee_id' => $l->employee_id,
            'employee_name' => $l->employee?->full_name,
            'employee_number' => $l->employee?->employee_number,
            'template' => $l->template,
            'template_name' => LetterService::TEMPLATES[$l->template][0] ?? $l->template,
            'subject' => $l->subject,
            'effective_date' => $l->effective_date?->toDateString(),
            'notes' => $l->notes,
            'generated_at' => $l->created_at,
            'generated_by' => $l->generator?->name,
            'url' => $this->url($l),
            'path' => '/api/employees/letters/'.$l->id.'/download',
        ];
    }
}
