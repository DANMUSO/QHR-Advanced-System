<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\Position;
use App\Models\User;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\Hazard;
use App\Modules\Compliance\Models\Incident;
use App\Modules\Employees\Services\LifecycleService;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 5 (lifecycle): import rules, probation on exit / extension / skipped stages, notice period, next of kin, H&S and DSR self-service. */
class QaRound5LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->hr = $this->user('hr-admin', $this->employee('Hr'));
    }

    private function employee(string $name, array $attrs = []): Employee
    {
        $e = new Employee($attrs + ['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.rand(10000000, 99999999),
            'employee_number' => uniqid('E'), 'gender' => 'female', 'date_of_joining' => now()->subYear()->toDateString()]);
        $e->company_id = $this->company->id;
        $e->status = 'active';
        $e->save();

        return $e->fresh();
    }

    private function user(string $role, ?Employee $employee = null): User
    {
        $user = User::create(['company_id' => $this->company->id, 'name' => $role.uniqid(), 'email' => uniqid($role).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $user->assignRole($role);
        $employee?->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    private function make(string $class, array $attrs)
    {
        $m = new $class($attrs);
        $m->company_id = $this->company->id;
        $m->save();

        return $m;
    }

    // ---- #2 import uses the Add-employee rules ---------------------------------------------------

    public function test_import_requires_national_id_and_valid_formats_per_row(): void
    {
        $this->make(Branch::class, ['name' => 'HQ']);
        $this->make(Department::class, ['name' => 'Finance']);
        $level = $this->make(EmploymentLevel::class, ['name' => 'Mid', 'probation_months' => 6, 'probation_review_days' => 30]);
        $this->make(Position::class, ['title' => 'Accountant', 'employment_level_id' => $level->id]);

        $head = "employee_number,first_name,last_name,email,phone,gender,date_of_birth,national_id,kra_pin,branch,department,position,date_of_joining,next_of_kin_name,next_of_kin_phone\n";
        $row = fn ($no, $email, $phone, $id, $kra) => "{$no},Jane,Doe,{$email},{$phone},female,1990-01-01,{$id},{$kra},HQ,Finance,Accountant,".now()->toDateString().",Kin Doe,0722000111\n";
        $csv = $head
            .$row('EMP-1', 'ok@t.test', '0712000001', '12345678', 'A123456789Z')
            .$row('EMP-2', 'noid@t.test', '0712000002', '', '')
            .$row('EMP-3', 'badkra@t.test', '0712000003', '22345678', 'BADPIN')
            .$row('EMP-4', 'badphone@t.test', '12', '32345678', '');
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $res = $this->actingAs($this->hr)->post('/api/organization/imports', ['type' => 'employees', 'file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame(3, $res->json('data.failed'));
        $errors = collect($res->json('data.errors'))->keyBy('row');
        $this->assertStringContainsStringIgnoringCase('national id', $errors[3]['message']);
        $this->assertStringContainsString('KRA PIN', $errors[4]['message']);
        $this->assertStringContainsStringIgnoringCase('phone', $errors[5]['message']);

        $jane = Employee::withoutGlobalScopes()->where('employee_number', 'EMP-1')->firstOrFail();
        $this->assertSame($level->id, $jane->employment_level_id, 'level taken from the position');
        $this->assertSame(1, $jane->nextOfKin()->count());
        $this->assertFalse(Employee::withoutGlobalScopes()->where('employee_number', 'EMP-2')->exists());
    }

    // ---- #11 probation review closed on exit -----------------------------------------------------

    public function test_exit_closes_the_open_probation_review(): void
    {
        $jane = $this->employee('Jane', ['probation_status' => 'on_probation', 'probation_end_date' => now()->addMonth()->toDateString()]);
        $review = app(ProbationRouting::class)->open($jane, notify: false);
        $this->assertSame('hr_review', $review->status, 'no reviewers: waits for HR');
        $this->assertSame(1, ProbationRouting::hrQueue($this->hr)->count());

        app(LifecycleService::class)->recordExit($jane, ['exit_date' => today()->toDateString(), 'reason' => 'termination'], $this->hr);

        $this->assertSame(ProbationRouting::CANCELLED, $review->fresh()->status);
        $this->assertSame(0, ProbationRouting::hrQueue($this->hr)->count());
        $this->assertStringContainsString('employee exited', $review->fresh()->hr_comment);
    }

    // ---- #36 extension must be later than the current end date -----------------------------------

    public function test_extend_rejects_an_end_date_before_the_current_one(): void
    {
        $end = now()->addMonths(2)->toDateString();
        $jane = $this->employee('Jane', ['probation_status' => 'on_probation', 'probation_end_date' => $end]);
        $review = app(ProbationRouting::class)->open($jane, notify: false);

        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision",
            ['decision' => 'extend', 'comment' => 'More time', 'extension_end_date' => now()->addMonth()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('extension_end_date');
        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision",
            ['decision' => 'extend', 'comment' => 'More time', 'extension_end_date' => $end])
            ->assertStatus(422)->assertJsonValidationErrors('extension_end_date');

        $later = now()->addMonths(3)->toDateString();
        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision",
            ['decision' => 'extend', 'comment' => 'More time', 'extension_end_date' => $later])->assertOk();
        $this->assertSame($later, $jane->fresh()->probation_end_date->toDateString());
    }

    // ---- #38 skipped stage reopens once someone can act ------------------------------------------

    public function test_skipped_supervisor_stage_reopens_when_the_supervisor_becomes_reachable(): void
    {
        $peter = $this->employee('Peter');
        $jane = $this->employee('Jane', ['primary_supervisor_id' => $peter->id, 'probation_status' => 'on_probation', 'probation_end_date' => now()->addMonth()->toDateString()]);
        $routing = app(ProbationRouting::class);
        $review = $routing->open($jane, notify: false);
        $this->assertSame('hr_review', $review->status, 'Peter has no login: every stage skipped');

        $peterUser = $this->user('supervisor', $peter);
        $this->assertTrue($routing->reopenSkipped($review->fresh(), notify: false));
        $review->refresh();
        $this->assertSame('in_review', $review->status);
        $this->assertSame(0, $review->stage_index);
        $this->assertSame($peter->id, (int) $review->primary_approver_id);
        $this->assertFalse($routing->reopenSkipped($review->fresh(), notify: false), 'idempotent');

        $this->actingAs($peterUser)->postJson("/api/probation/reviews/{$review->id}/review", ['comment' => 'Doing well'])->assertOk();
        $this->assertSame('hr_review', $review->fresh()->status);

        $stages = $this->actingAs($this->hr)->getJson("/api/probation/employees/{$jane->id}")->assertOk()->json('data.stages');
        $this->assertSame('done', $stages[0]['state'], 'the reopened stage shows as done, not skipped');
        $this->assertSame('skipped', $stages[1]['state']);
    }

    // ---- #39 resignation notice period -----------------------------------------------------------

    public function test_resignation_respects_the_notice_period_unless_hr_waives_it(): void
    {
        $jane = $this->employee('Jane');
        $janeUser = $this->user('employee', $jane);

        $this->actingAs($janeUser)->getJson('/api/profile/requests')->assertOk()
            ->assertJsonPath('resignation_notice.days', 30)
            ->assertJsonPath('resignation_notice.earliest_last_day', today()->addDays(30)->toDateString());

        $this->actingAs($janeUser)->postJson('/api/profile/resignations', ['last_working_day' => today()->addDays(10)->toDateString(), 'reason' => 'New job offer'])
            ->assertStatus(422)->assertJsonValidationErrors('last_working_day');

        $this->company->forceFill(['settings' => ['resignation_notice_days' => 7]])->save();
        $this->actingAs($janeUser)->postJson('/api/profile/resignations', ['last_working_day' => today()->addDays(10)->toDateString(), 'reason' => 'New job offer'])
            ->assertCreated();

        // HR processing the resignation earlier than the notice allows needs an explicit waiver.
        $this->actingAs($this->hr)->postJson("/api/employees/{$jane->id}/exit", ['exit_date' => today()->addDays(3)->toDateString(), 'reason' => 'resignation'])
            ->assertStatus(422)->assertJsonValidationErrors('exit_date');
        $this->actingAs($this->hr)->postJson("/api/employees/{$jane->id}/exit", ['exit_date' => today()->addDays(3)->toDateString(), 'reason' => 'resignation', 'waive_notice' => true])
            ->assertCreated();
    }

    // ---- #40 next of kin carried to the new employment record ------------------------------------

    public function test_reinstatement_carries_next_of_kin_from_the_latest_period_that_has_one(): void
    {
        $neema = $this->employee('Neema');
        $neema->nextOfKin()->create(['name' => 'Kin One', 'relationship' => 'mother', 'phone' => '0711000001']);
        $lifecycle = app(LifecycleService::class);
        $employment = fn () => ['date_of_joining' => today()->addDay()->toDateString(), 'contract_type' => 'permanent'];

        $lifecycle->recordExit($neema, ['exit_date' => today()->toDateString(), 'reason' => 'termination'], $this->hr);
        $second = $lifecycle->reinstate($neema->fresh(), $employment(), false, $this->hr);
        $this->assertSame(['Kin One'], $second->nextOfKin()->pluck('name')->all());

        // A middle period that lost its next of kin still passes on the earlier one.
        $second->nextOfKin()->delete();
        $second->forceFill(['status' => 'exited'])->save();
        \App\Modules\Employees\Models\ExitRecord::create(['company_id' => $this->company->id, 'employee_id' => $second->id,
            'exit_date' => today()->addDays(2)->toDateString(), 'reason' => 'termination', 'status' => 'completed']);
        $third = $lifecycle->reinstate($second->fresh(), ['date_of_joining' => today()->addDays(5)->toDateString(), 'contract_type' => 'permanent'], false, $this->hr);
        $this->assertSame(['Kin One'], $third->nextOfKin()->pluck('name')->all());
    }

    // ---- #44 / #56 employee H&S and DSR self-service ---------------------------------------------

    public function test_employee_reports_safety_issues_and_makes_data_requests(): void
    {
        $jane = $this->employee('Jane');
        $janeUser = $this->user('employee', $jane);

        $this->actingAs($janeUser)->postJson('/api/my/health-safety', ['kind' => 'hazard', 'title' => 'Loose cable', 'date' => today()->addDay()->toDateString(),
            'description' => 'Cable across the corridor by the lifts.'])->assertStatus(422)->assertJsonValidationErrors('date');
        $this->actingAs($janeUser)->postJson('/api/my/health-safety', ['kind' => 'hazard', 'title' => 'Loose cable', 'level' => 'high',
            'description' => 'Cable across the corridor by the lifts.'])->assertCreated();
        $this->actingAs($janeUser)->postJson('/api/my/health-safety', ['kind' => 'incident', 'title' => 'Slipped', 'category' => 'accident', 'level' => 'minor',
            'date' => now()->subHour()->format('Y-m-d H:i'), 'description' => 'Slipped on a wet floor in the kitchen.', 'affected_me' => true])->assertCreated();

        $this->assertSame(1, Hazard::where('reported_by_user_id', $janeUser->id)->count());
        $this->assertSame($jane->id, Incident::where('reported_by_user_id', $janeUser->id)->value('employee_id'));
        $this->actingAs($janeUser)->getJson('/api/my/health-safety')->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($janeUser)->postJson('/api/my/data-requests', ['type' => 'access', 'description' => 'Please send me a copy of my file.'])->assertCreated();
        $this->actingAs($janeUser)->postJson('/api/my/data-requests', ['type' => 'access', 'description' => 'Please send me a copy of my file.'])->assertStatus(422);
        $dsr = DataRequest::where('employee_id', $jane->id)->firstOrFail();
        $this->assertSame($jane->full_name, $dsr->requester_name);
        $this->assertSame(today()->addDays(7)->toDateString(), $dsr->due_date->toDateString());
        $this->actingAs($janeUser)->getJson('/api/my/data-requests')->assertOk()->assertJsonCount(1, 'data');

        // Another employee sees none of it.
        $other = $this->user('employee', $this->employee('Tom'));
        $this->actingAs($other)->getJson('/api/my/health-safety')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($other)->getJson('/api/my/data-requests')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hr_registers_reject_future_dates_and_link_data_requests_to_an_employee(): void
    {
        $admin = User::create(['company_id' => $this->company->id, 'name' => 'Admin', 'email' => 'adm@t.test', 'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $admin->assignRole('super-admin');
        $jane = $this->employee('Jane');

        $this->actingAs($admin)->postJson('/api/compliance/health-safety', ['title' => 'Wet floor', 'reported_date' => today()->addDays(2)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('reported_date');
        $this->actingAs($admin)->postJson('/api/compliance/incidents', ['title' => 'Fall', 'incident_date' => now()->addDay()->toDateTimeString()])
            ->assertStatus(422)->assertJsonValidationErrors('incident_date');

        $this->actingAs($admin)->postJson('/api/compliance/data-protection', ['requester_name' => 'Free text', 'type' => 'access'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_id');
        $this->actingAs($admin)->postJson('/api/compliance/data-protection', ['employee_id' => $jane->id, 'type' => 'erasure', 'company_id' => $this->company->id])
            ->assertCreated()->assertJsonPath('data.employee_id', $jane->id)->assertJsonPath('data.requester_name', $jane->full_name);
    }
}
