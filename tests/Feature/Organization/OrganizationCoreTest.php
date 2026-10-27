<?php

namespace Tests\Feature\Organization;

use App\Dashboard\Widgets\ApprovalsInboxWidget;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentLevel;
use App\Models\User;
use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Organization\Models\Workflow;
use App\Support\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Organisation / core fixes: inactive companies, workflow designer, bulk import onboarding, approvals inbox, support tickets, timeouts. */
class OrganizationCoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        config(['hr.enforce_two_factor' => false]);
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
    }

    private function user(array $roles, ?Company $company = null, bool $withEmployee = false): User
    {
        $company ??= $this->company;
        $user = User::create([
            'company_id' => $company->id, 'name' => 'User '.implode('-', $roles), 'email' => uniqid($roles[0]).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);
        if ($withEmployee) {
            $this->employee($company, $user);
        }

        return $user->fresh();
    }

    private function employee(Company $company, ?User $user = null, string $number = null): Employee
    {
        $e = new Employee(['first_name' => 'Emp', 'last_name' => 'Loyee', 'phone' => '0712345678', 'employee_number' => $number ?? uniqid('E'), 'email' => $user?->email]);
        $e->company_id = $company->id;
        $e->user_id = $user?->id;
        $e->status = 'active';
        $e->gender = 'female';
        $e->save();

        return $e;
    }

    // ---- inactive company --------------------------------------------------------------------

    public function test_inactive_company_blocks_login(): void
    {
        $user = $this->user(['employee']);
        $this->company->update(['is_active' => false]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Password@123'])
            ->assertStatus(403)->assertJsonFragment(['email' => [AccountAccess::INACTIVE_COMPANY_MESSAGE]]);
        $this->assertGuest('web');
    }

    public function test_inactive_company_ends_an_existing_session_but_not_for_super_admin(): void
    {
        $user = $this->user(['employee']);
        $this->actingAs($user)->getJson('/api/notifications/unread-count')->assertOk();

        $this->company->update(['is_active' => false]);
        $this->actingAs($user->fresh())->getJson('/api/notifications/unread-count')->assertStatus(401)->assertJson(['code' => 'company_inactive']);

        $admin = $this->user(['super-admin']);
        $this->actingAs($admin)->getJson('/api/notifications/unread-count')->assertOk();
    }

    public function test_idle_session_is_signed_out_and_background_requests_do_not_count(): void
    {
        $user = $this->user(['employee']);
        $this->company->update(['settings' => ['idle_timeout_minutes' => 15]]);

        // A background poll inside the window is allowed but does not refresh the activity stamp.
        $stamp = time() - 10 * 60;
        $this->actingAs($user->fresh())
            ->withHeaders(['Referer' => 'http://localhost', AccountAccess::BACKGROUND_HEADER => '1'])->withSession(['auth.started_at' => time() - 1200, 'auth.last_activity_at' => $stamp])
            ->getJson('/api/notifications/unread-count')->assertOk();
        $this->assertSame($stamp, session('auth.last_activity_at'));

        $this->actingAs($user->fresh())
            ->withHeader('Referer', 'http://localhost')->withSession(['auth.started_at' => time() - 1200, 'auth.last_activity_at' => time() - 16 * 60])
            ->getJson('/api/notifications/unread-count')->assertStatus(401)->assertJson(['code' => 'session_idle']);

        $this->actingAs($user->fresh())
            ->withHeader('Referer', 'http://localhost')->withSession(['auth.started_at' => time() - 5 * 3600, 'auth.last_activity_at' => time() - 60])
            ->getJson('/api/notifications/unread-count')->assertStatus(401)->assertJson(['code' => 'session_expired']);
    }

    // ---- workflows ---------------------------------------------------------------------------

    public function test_workflow_saves_with_steps_and_keeps_one_active_per_trigger(): void
    {
        $hr = $this->user(['hr-admin']);

        $this->actingAs($hr)->postJson('/api/organization/workflows', ['name' => 'No steps', 'trigger' => 'leave_request', 'steps' => []])
            ->assertStatus(422)->assertJsonValidationErrors('steps');
        $this->actingAs($hr)->postJson('/api/organization/workflows', ['name' => 'Bad role', 'trigger' => 'leave_request', 'steps' => [['type' => 'role']]])
            ->assertStatus(422)->assertJsonValidationErrors('steps.0.role');

        $first = $this->actingAs($hr)->postJson('/api/organization/workflows', [
            'name' => 'Leave chain', 'trigger' => 'leave_request',
            'steps' => [['type' => 'supervisor'], ['type' => 'role', 'role' => 'hr-admin', 'label' => 'HR sign-off']],
        ])->assertCreated()->json('data');

        $wf = Workflow::find($first['id']);
        $this->assertCount(2, $wf->steps);
        $this->assertSame('hr-admin', $wf->steps[1]['role']);
        $this->assertSame('Primary supervisor', $wf->steps[0]['label']);
        $this->assertSame($wf->steps, Workflow::stepsFor($this->company->id, 'leave_request'));

        $this->actingAs($hr)->postJson('/api/organization/workflows', ['name' => 'Second', 'trigger' => 'leave_request', 'steps' => [['type' => 'hod']]])
            ->assertCreated();
        $this->assertFalse($wf->fresh()->is_active);

        $list = $this->actingAs($hr)->getJson('/api/organization/workflows')->assertOk();
        $this->assertContains('leave_request', $list->json('options.honoured_triggers'));
        $this->assertNotEmpty($list->json('options.step_types'));
    }

    // ---- bulk import -------------------------------------------------------------------------

    public function test_bulk_import_onboards_new_employees(): void
    {
        Event::fake([EmployeeHired::class]);
        $hr = $this->user(['hr-admin'], null, true);
        $supervisor = $this->employee($this->company, null, 'SUP-1');
        $level = new EmploymentLevel(['name' => 'Mid-level', 'probation_months' => 6, 'probation_review_days' => 30]);
        $level->company_id = $this->company->id;
        $level->save();
        $dept = new Department(['name' => 'Finance']);
        $dept->company_id = $this->company->id;
        $dept->save();

        // New rows are checked with the Add employee rules (QA round 5): personal, statutory and employment fields plus a next of kin.
        $branch = new \App\Models\Branch(['name' => 'HQ']);
        $branch->company_id = $this->company->id;
        $branch->save();
        $position = new \App\Models\Position(['title' => 'Accountant']);
        $position->company_id = $this->company->id;
        $position->save();
        $csv = "employee_number,first_name,last_name,email,phone,gender,date_of_birth,national_id,branch,department,position,level,date_of_joining,contract_type,supervisor,create_login,next_of_kin_name,next_of_kin_phone\n"
            .'EMP-9001,Jane,Muthoni,jane.m@example.test,0712009001,female,1990-05-05,29001001,HQ,Finance,Accountant,Mid-level,'.now()->subMonth()->toDateString().",permanent,SUP-1,yes,Kin Muthoni,0722009001\n"
            ."EMP-9002,No,Email,,0712009002,female,1990-05-05,29001002,HQ,Finance,Accountant,,,permanent,,yes,Kin Email,0722009002\n";
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);

        $res = $this->actingAs($hr)->post('/api/organization/imports', ['type' => 'employees', 'file' => $file], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->assertSame(1, $res->json('data.imported'));
        $this->assertSame(1, $res->json('data.failed'));

        $jane = Employee::withoutGlobalScopes()->where('employee_number', 'EMP-9001')->firstOrFail();
        $this->assertSame($supervisor->id, $jane->primary_supervisor_id);
        $this->assertSame('on_probation', $jane->probation_status);
        $this->assertNotNull($jane->probation_end_date);
        $this->assertNotNull($jane->user_id, 'login created');
        $this->assertTrue(DB::table('employee_contracts')->where('employee_id', $jane->id)->exists(), 'contract created');
        $this->assertFalse(Employee::withoutGlobalScopes()->where('employee_number', 'EMP-9002')->exists(), 'row without email rolled back');
        Event::assertDispatched(EmployeeHired::class, fn ($e) => $e->employee->is($jane));
        Event::assertDispatchedTimes(EmployeeHired::class, 1);
    }

    // ---- approvals inbox ---------------------------------------------------------------------

    public function test_approvals_inbox_shows_for_a_plain_employee_with_a_pending_handover(): void
    {
        LeaveType::seedDefaults($this->company);
        $colleague = $this->user(['employee'], null, true);
        $requester = $this->employee($this->company);
        $widget = app(ApprovalsInboxWidget::class);

        $this->actingAs($colleague);
        $this->assertFalse($widget->visible($colleague), 'nothing pending yet');

        $request = new LeaveRequest();
        $request->forceFill([
            'company_id' => $this->company->id, 'employee_id' => $requester->id,
            'leave_type_id' => LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id'),
            'start_date' => now()->addWeek()->toDateString(), 'end_date' => now()->addWeek()->addDays(2)->toDateString(), 'days' => 3,
            'status' => LeaveRequest::PENDING_HANDOVER, 'handover1_id' => $colleague->employee->id, 'handover1_status' => 'pending',
        ])->save();

        $this->assertTrue($widget->visible($colleague->fresh()));
        $this->assertSame(1, $widget->data($colleague->fresh())['total']);
    }

    // ---- backups -----------------------------------------------------------------------------

    public function test_backup_includes_child_tables_and_validates_for_restore(): void
    {
        $other = Company::create(['name' => 'Other Ltd', 'code' => 'OTH', 'is_active' => true]);
        $mine = $this->employee($this->company);
        $theirs = $this->employee($other);
        $benefit = new \App\Models\Benefit(['name' => 'Gym']);
        $benefit->company_id = $this->company->id;
        $benefit->save();
        DB::table('benefit_employee')->insert([['benefit_id' => $benefit->id, 'employee_id' => $mine->id]]);

        $service = app(\App\Modules\Organization\Services\BackupService::class);
        $tables = $service->collect($this->company->id);

        $this->assertSame([$mine->id], array_column($tables['employees'], 'id'));
        $this->assertCount(1, $tables['benefit_employee'], 'pivot without company_id is included through its foreign key');
        $this->assertArrayNotHasKey('password', $tables['users'][0] ?? []);

        $raw = gzencode(json_encode(['meta' => ['company' => ['id' => $this->company->id, 'name' => 'Acme Ltd'], 'generated_at' => now()->toIso8601String()], 'tables' => $tables]));
        $report = $service->validateFile($raw);
        $this->assertSame($this->company->id, $report['company']['id']);

        $tables['employees'][] = (array) DB::table('employees')->find($theirs->id);
        $this->expectExceptionMessage('another company');
        $service->validateFile(gzencode(json_encode(['meta' => ['company' => ['id' => $this->company->id, 'name' => 'Acme Ltd']], 'tables' => $tables])));
    }

    // ---- support tickets ---------------------------------------------------------------------

    public function test_any_user_can_raise_and_see_only_their_own_tickets(): void
    {
        $employee = $this->user(['employee']);
        $other = $this->user(['employee']);
        $hr = $this->user(['hr-admin']);

        $this->actingAs($employee)->postJson('/api/organization/support', [
            'subject' => 'Cannot see payslip', 'category' => array_key_first(\App\Modules\Organization\Models\SupportTicket::CATEGORIES),
            'priority' => \App\Modules\Organization\Models\SupportTicket::PRIORITIES[0], 'description' => 'My October payslip is missing.',
        ])->assertCreated();

        $this->actingAs($employee)->getJson('/api/organization/support')->assertOk()->assertJsonPath('total', 1);
        $this->actingAs($other)->getJson('/api/organization/support')->assertOk()->assertJsonPath('total', 0);
        $this->actingAs($hr)->getJson('/api/organization/support')->assertOk()->assertJsonPath('total', 1);
    }
}
