<?php

namespace App\Modules\Performance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Modules\Performance\Http\Controllers\Concerns\PresentsPeople;
use App\Modules\Performance\Models\Certification;
use App\Modules\Performance\Models\Course;
use App\Modules\Performance\Models\EmployeeSkill;
use App\Modules\Performance\Models\Enrollment;
use App\Modules\Performance\Models\Skill;
use App\Modules\Performance\Notifications\PerformanceNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Learning & Development: training catalogue, enrolments, skills matrix and certifications. */
class LearningController extends Controller
{
    use PresentsPeople;

    // ---------------------------------------------------------------- catalogue

    public function catalogue(Request $request): JsonResponse
    {
        $rows = Course::withCount(['enrollments as enrolled' => fn ($q) => $q->where('status', '!=', 'cancelled'),
            'enrollments as completed' => fn ($q) => $q->where('status', 'completed')])
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $course = new Course($this->validatedCourse($request));
        $course->company_id = $this->writeCompanyId($request);
        $course->save();

        return response()->json(['data' => $course, 'message' => 'Course added.'], 201);
    }

    public function updateCourse(Request $request, Course $course): JsonResponse
    {
        $course->update($this->validatedCourse($request, true));

        return response()->json(['data' => $course, 'message' => 'Course updated.']);
    }

    public function destroyCourse(Course $course): JsonResponse
    {
        abort_if($course->enrollments()->where('status', 'completed')->exists(), 422, 'Staff have completed this course; deactivate it instead.');
        $course->delete();

        return response()->json(['message' => 'Course deleted.']);
    }

    public function enrollments(Request $request, Course $course): JsonResponse
    {
        $rows = $course->enrollments()->with('employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name')->latest()->get();

        return response()->json(['data' => $rows->map(fn ($e) => $e->toArray() + ['employee' => $this->person($e->employee)])]);
    }

    public function enroll(Request $request, Course $course): JsonResponse
    {
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', 'exists:employees,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);
        $employees = Employee::whereIn('id', $data['employee_ids'])->where('company_id', $course->company_id)->get();
        $added = 0;
        foreach ($employees as $employee) {
            $enrollment = Enrollment::firstOrNew(['course_id' => $course->id, 'employee_id' => $employee->id]);
            if ($enrollment->exists && $enrollment->status !== 'cancelled') {
                continue;
            }
            $enrollment->fill(['start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null, 'status' => 'enrolled',
                'progress' => 0, 'assigned_by_user_id' => $request->user()->id])->save();
            $added++;
            $employee->user?->notify(new PerformanceNotification('Training assigned', "You have been enrolled in \"{$course->name}\".", '/performance?tab=learning', 'graduation-cap', false));
        }

        return response()->json(['message' => $added ? "Enrolled {$added} employee".($added === 1 ? '' : 's').'.' : 'Everyone selected is already enrolled.']);
    }

    public function updateEnrollment(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless(Course::whereKey($enrollment->course_id)->exists(), 404); // company scope via course
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(Enrollment::STATUSES)],
            'progress' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date'],
        ]);
        $this->applyProgress($enrollment, $data);

        return response()->json(['data' => $enrollment, 'message' => 'Enrolment updated.']);
    }

    /** The signed-in employee's training. */
    public function my(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        if (! $me) {
            return response()->json(['data' => []]);
        }
        $rows = Enrollment::where('employee_id', $me->id)->with('course')->orderByRaw("CASE status WHEN 'in_progress' THEN 1 WHEN 'enrolled' THEN 2 WHEN 'completed' THEN 3 WHEN 'cancelled' THEN 4 ELSE 5 END")->latest()->get();

        return response()->json(['data' => $rows->map(fn ($e) => [
            'id' => $e->id, 'course_id' => $e->course_id, 'course_name' => $e->course?->name, 'provider' => $e->course?->provider,
            'category' => $e->course?->category, 'delivery' => $e->course?->delivery, 'duration_hours' => $e->course?->duration_hours,
            'start_date' => $e->start_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(),
            'status' => $e->status, 'progress' => $e->progress, 'completed_at' => $e->completed_at?->toDateString(),
        ])]);
    }

    public function updateMy(Request $request, Enrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->employee_id === $request->user()->employee?->id, 403);
        $data = $request->validate([
            'progress' => ['required_without:status', 'integer', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(['enrolled', 'in_progress', 'completed', 'cancelled'])],
        ]);
        abort_if($enrollment->status === 'completed' && ($data['status'] ?? 'completed') !== 'completed', 422, 'This course is already completed.');
        $this->applyProgress($enrollment, $data);

        return response()->json(['data' => $enrollment->fresh(), 'message' => 'Progress updated.']);
    }

    /** Self-service: active (published) courses with my enrolment status. */
    public function selfCatalogue(Request $request): JsonResponse
    {
        $me = $request->user()->employee;
        $mine = $me ? Enrollment::where('employee_id', $me->id)->get()->keyBy('course_id') : collect();
        $rows = Course::where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->query('search').'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->query('category')))
            ->orderBy('name')->get();

        return response()->json(['data' => $rows->map(fn (Course $c) => [
            'id' => $c->id, 'name' => $c->name, 'category' => $c->category, 'provider' => $c->provider,
            'duration_hours' => $c->duration_hours, 'delivery' => $c->delivery, 'description' => $c->description,
            'enrollment_id' => $mine[$c->id]?->id ?? null,
            'enrollment_status' => isset($mine[$c->id]) && $mine[$c->id]->status !== 'cancelled' ? $mine[$c->id]->status : null,
            'can_enrol' => $me && (! isset($mine[$c->id]) || $mine[$c->id]->status === 'cancelled'),
        ])]);
    }

    /** Self-service: enrol myself in an active course. */
    public function selfEnrol(Request $request, Course $course): JsonResponse
    {
        $me = $request->user()->employee;
        abort_unless($me, 422, 'Your account is not linked to an employee record.');
        abort_unless($course->is_active, 422, 'This course is not open for enrolment.');
        $enrollment = Enrollment::firstOrNew(['course_id' => $course->id, 'employee_id' => $me->id]);
        abort_if($enrollment->exists && $enrollment->status !== 'cancelled', 422, 'You are already enrolled in this course.');
        $enrollment->forceFill(['company_id' => $course->company_id]);
        $enrollment->fill(['status' => 'enrolled', 'progress' => 0, 'start_date' => today(), 'completed_at' => null, 'assigned_by_user_id' => null])->save();

        return response()->json(['data' => $enrollment, 'message' => "Enrolled in \"{$course->name}\"."], 201);
    }

    // ---------------------------------------------------------------- skills matrix

    public function skills(Request $request): JsonResponse
    {
        $skills = Skill::orderBy('category')->orderBy('name')->get(['id', 'name', 'category']);
        $employees = Employee::active()->with('department:id,name', 'position:id,title')
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->orderBy('first_name')->limit(300)->get();
        $levels = EmployeeSkill::whereIn('employee_id', $employees->pluck('id'))->whereIn('skill_id', $skills->pluck('id'))->get()->groupBy('employee_id');

        return response()->json(['data' => [
            'skills' => $skills,
            'employees' => $employees->map(fn ($e) => $this->person($e) + [
                'skills' => (object) ($levels[$e->id] ?? collect())->pluck('level', 'skill_id')->all(),
            ]),
        ]]);
    }

    public function storeSkill(Request $request): JsonResponse
    {
        $companyId = $this->writeCompanyId($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('ld_skills')->where('company_id', $companyId)],
            'category' => ['nullable', 'string', 'max:80'],
        ]);
        $skill = new Skill($data);
        $skill->company_id = $companyId;
        $skill->save();

        return response()->json(['data' => $skill, 'message' => 'Skill added.'], 201);
    }

    public function destroySkill(Skill $skill): JsonResponse
    {
        $skill->delete();

        return response()->json(['message' => 'Skill removed.']);
    }

    public function assessSkill(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'skill_id' => ['required', 'integer', 'exists:ld_skills,id'],
            'level' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);
        Skill::findOrFail($data['skill_id']); // company scope
        Employee::findOrFail($data['employee_id']);
        if (empty($data['level'])) {
            EmployeeSkill::where(['employee_id' => $data['employee_id'], 'skill_id' => $data['skill_id']])->delete();
        } else {
            EmployeeSkill::updateOrCreate(['employee_id' => $data['employee_id'], 'skill_id' => $data['skill_id']],
                ['level' => $data['level'], 'assessed_by_user_id' => $request->user()->id]);
        }

        return response()->json(['message' => 'Skill level saved.']);
    }

    // ---------------------------------------------------------------- certifications

    public function certifications(Request $request): JsonResponse
    {
        $rows = Certification::with('employee:id,first_name,last_name,photo_path,department_id', 'employee.department:id,name')
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->integer('employee_id')))
            ->orderByRaw('expiry_date IS NULL, expiry_date')->get()
            ->when($request->filled('status'), fn ($c) => $c->where('status', $request->query('status')));

        return response()->json(['data' => $rows->values()->map(fn ($c) => $this->certRow($c))]);
    }

    public function storeCertification(Request $request): JsonResponse
    {
        $data = $this->validatedCert($request);
        $employee = $this->findEmployee($data['employee_id']);
        $cert = new Certification([
            'employee_id' => $employee->id, 'name' => $data['certification_name'], 'issuing_body' => $data['issuing_body'] ?? null,
            'issue_date' => $data['issue_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null, 'credential_id' => $data['credential_id'] ?? null,
        ]);
        $cert->company_id = $employee->company_id;
        $cert->save();

        return response()->json(['data' => $this->certRow($cert->load('employee.department')), 'message' => 'Certification recorded.'], 201);
    }

    public function updateCertification(Request $request, Certification $certification): JsonResponse
    {
        $data = $this->validatedCert($request, true);
        $certification->update(array_filter([
            'name' => $data['certification_name'] ?? null, 'issuing_body' => $data['issuing_body'] ?? null,
            'issue_date' => $data['issue_date'] ?? null, 'expiry_date' => $data['expiry_date'] ?? null, 'credential_id' => $data['credential_id'] ?? null,
        ], fn ($v) => $v !== null));

        return response()->json(['data' => $this->certRow($certification->load('employee.department')), 'message' => 'Certification updated.']);
    }

    public function destroyCertification(Certification $certification): JsonResponse
    {
        $certification->delete();

        return response()->json(['message' => 'Certification deleted.']);
    }

    // ---------------------------------------------------------------- helpers

    private function applyProgress(Enrollment $enrollment, array $data): void
    {
        if (isset($data['progress'])) {
            $data['status'] ??= $data['progress'] >= 100 ? 'completed' : ($data['progress'] > 0 ? 'in_progress' : $enrollment->status);
        }
        if (($data['status'] ?? null) === 'completed') {
            $data['progress'] = 100;
            $data['completed_at'] = $enrollment->completed_at ?? now()->toDateString();
        }
        $enrollment->update($data);
    }

    private function validatedCourse(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:80'], 'provider' => ['nullable', 'string', 'max:150'],
            'duration_hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'delivery' => ['nullable', Rule::in(Course::DELIVERY)], 'cost' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:3000'], 'is_active' => ['boolean'],
        ]);
    }

    private function validatedCert(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'employee_id' => [$updating ? 'prohibited' : 'required'],
            'certification_name' => [$updating ? 'sometimes' : 'required', 'string', 'max:200'],
            'issuing_body' => ['nullable', 'string', 'max:150'], 'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'], 'credential_id' => ['nullable', 'string', 'max:100'],
        ], [], ['employee_id' => 'employee']);
    }

    private function certRow(Certification $c): array
    {
        return [
            'id' => $c->id, 'employee' => $this->person($c->employee), 'employee_name' => $c->employee?->full_name,
            'department' => $c->employee?->department?->name,
            'certification_name' => $c->name, 'issuing_body' => $c->issuing_body, 'credential_id' => $c->credential_id,
            'issue_date' => $c->issue_date?->toDateString(), 'expiry_date' => $c->expiry_date?->toDateString(), 'status' => $c->status,
        ];
    }
}
