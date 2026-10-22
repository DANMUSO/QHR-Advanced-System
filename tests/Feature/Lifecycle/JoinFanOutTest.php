<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Employees\Notifications\EmployeeNotification;
use App\Modules\Employees\Services\LifecycleService;
use App\Modules\Payroll\Models\PayrollPayProfile;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Recruitment\Models\InductionTask;
use App\Modules\Recruitment\Models\OnboardingTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Joining a company by any route (hire, transfer in, re-instatement) must reach every module, not only Payroll and Leave:
 * probation review, appraisal enrolment, onboarding checklist, the personal file and the new supervisor's heads-up.
 */
class JoinFanOutTest extends TestCase
{
    use RefreshDatabase;

    private Company $from;

    private Company $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->from = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->to = Company::create(['name' => 'Beta Ltd', 'code' => 'BET', 'is_active' => true]);
    }

    private function employee(Company $company, string $name, array $attrs = []): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.rand(10000000, 99999999),
            'employee_number' => uniqid('E'), 'gender' => 'female', 'date_of_joining' => now()->subYear()->toDateString()] + $attrs);
        $e->company_id = $company->id;
        $e->status = 'active';
        $e->save();

        return $e->fresh();
    }

    private function user(Company $company, string $role, ?Employee $employee = null): User
    {
        $user = User::create(['company_id' => $company->id, 'name' => $role.uniqid(), 'email' => uniqid($role).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $user->assignRole($role);
        $employee?->forceFill(['user_id' => $user->id])->save();

        return $user->fresh();
    }

    public function test_company_transfer_reaches_every_module_in_the_target_company(): void
    {
        $hrFrom = $this->user($this->from, 'hr-admin');
        $hrTo = $this->user($this->to, 'hr-admin');
        $boss = $this->employee($this->to, 'Bea');
        $bossUser = $this->user($this->to, 'supervisor', $boss);

        $mover = $this->employee($this->from, 'Mia');
        $this->user($this->from, 'employee', $mover);

        $cycle = (new Cycle(['name' => '2026', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear(), 'status' => 'active']))
            ->forceFill(['company_id' => $this->to->id]);
        $cycle->save();
        (new InductionTask(['name' => 'Site safety briefing', 'due_days' => 2]))->forceFill(['company_id' => $this->to->id])->save();

        $id = (new EmployeeDocument(['employee_id' => $mover->id, 'title' => 'National ID', 'file_path' => 'x/id.pdf', 'file_name' => 'id.pdf']))
            ->forceFill(['company_id' => $this->from->id]);
        $id->save();
        $warning = (new EmployeeDocument(['employee_id' => $mover->id, 'title' => 'Warning', 'file_path' => 'x/w.pdf', 'file_name' => 'w.pdf', 'is_confidential' => true]))
            ->forceFill(['company_id' => $this->from->id]);
        $warning->save();

        $branch = Branch::withoutGlobalScopes()->create(['company_id' => $this->to->id, 'name' => 'Mombasa']);
        $dept = Department::withoutGlobalScopes()->create(['company_id' => $this->to->id, 'name' => 'Sales']);
        $position = Position::withoutGlobalScopes()->create(['company_id' => $this->to->id, 'title' => 'Sales Lead']);

        $this->actingAs($hrFrom);
        $new = app(LifecycleService::class)->transferCompany($mover, $this->to->id, [
            'employee_number' => 'B-001', 'date_of_joining' => now()->toDateString(), 'branch_id' => $branch->id,
            'department_id' => $dept->id, 'position_id' => $position->id, 'primary_supervisor_id' => $boss->id,
            'contract_type' => 'permanent', 'probation_months' => 3, 'carry_leave_balances' => true,
        ], $hrFrom);

        $this->assertSame('on_probation', $new->fresh()->probation_status);
        $this->assertTrue(ProbationReview::withoutGlobalScopes()->where('employee_id', $new->id)->exists(), 'probation review opened');
        $this->assertTrue(Appraisal::withoutGlobalScopes()->where('employee_id', $new->id)->where('cycle_id', $cycle->id)->exists(), 'enrolled in target cycle');
        $this->assertSame(1, OnboardingTask::withoutGlobalScopes()->where('employee_id', $new->id)->count(), 'target company onboarding');
        $this->assertFalse((bool) PayrollPayProfile::withoutGlobalScopes()->where('employee_id', $new->id)->value('on_hold'), 'pay profile active');

        Notification::assertSentTo($bossUser, EmployeeNotification::class, fn ($n) => str_contains($n->title, 'transfers in'));
        Notification::assertSentTo($hrTo, EmployeeNotification::class);

        // The personal file follows the person; the old company's confidential warning does not.
        $titles = $this->actingAs($hrTo)->getJson("/api/documents/employees/{$new->id}")->assertOk()->json('documents.*.title');
        $this->assertContains('National ID', $titles);
        $this->assertNotContains('Warning', $titles);
        $this->actingAs($hrTo)->getJson("/api/documents/employee-docs/{$id->id}")->assertOk();
        $this->actingAs($hrTo)->getJson("/api/documents/employee-docs/{$warning->id}")->assertForbidden();
    }
}
