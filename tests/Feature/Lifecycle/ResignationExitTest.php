<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\ResignationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 2: a scheduled exit closes the resignation at once; contract staff need an end date. */
class ResignationExitTest extends TestCase
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
        $this->hr = $this->user('hr-admin');
    }

    private function employee(string $name): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.rand(10000000, 99999999),
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

    public function test_scheduled_exit_closes_the_resignation_and_blocks_a_second_exit(): void
    {
        $jane = $this->employee('Jane');
        $janeUser = $this->user('employee', $jane);
        $leaving = now()->addMonth()->toDateString();

        $this->actingAs($janeUser)->postJson('/api/profile/resignations', ['last_working_day' => $leaving, 'reason' => 'New job'])->assertCreated();
        $r = ResignationRequest::where('employee_id', $jane->id)->firstOrFail();
        $this->assertSame('pending', $r->status);

        $exitId = $this->actingAs($this->hr)->postJson('/api/employees/exits', ['employee_id' => $jane->id, 'exit_date' => $leaving, 'reason' => 'resignation'])
            ->assertSuccessful()->json('data.id') ?? \App\Modules\Employees\Models\ExitRecord::where('employee_id', $jane->id)->value('id');

        $r->refresh();
        $this->assertSame('processed', $r->status);
        $this->assertSame((int) $exitId, (int) $r->exit_id);
        $this->assertSame('active', $jane->fresh()->status); // exit date still in the future

        // "Process exit" is no longer offered and a second exit is refused.
        $row = collect($this->actingAs($this->hr)->getJson('/api/staff-requests/resignations?status=all')->assertOk()->json('data'))->firstWhere('id', $r->id);
        $this->assertTrue($row['has_exit']);
        $this->assertSame('scheduled', $row['exit']['status']);
        $this->actingAs($this->hr)->postJson('/api/employees/exits', ['employee_id' => $jane->id, 'exit_date' => $leaving, 'reason' => 'resignation'])
            ->assertStatus(422);

        // Directory shows the "Leaving" marker; the profile no longer offers Exit.
        $listed = collect($this->actingAs($this->hr)->getJson('/api/employees?per_page=50')->assertOk()->json('data'))->firstWhere('id', $jane->id);
        $this->assertSame($leaving, $listed['leaving_on']);
        $this->assertFalse($this->actingAs($this->hr)->getJson("/api/employees/{$jane->id}")->assertOk()->json('data.abilities.exit'));

        // Cancelling the exit puts the resignation back in HR's queue.
        $this->actingAs($this->hr)->postJson("/api/employees/exits/{$exitId}/cancel")->assertSuccessful();
        $this->assertSame('pending', $r->fresh()->status);
        $this->assertNull($r->fresh()->exit_id);
    }

    public function test_probation_routes_to_a_not_yet_activated_supervisor_and_files_the_letter(): void
    {
        $peter = $this->employee('Peter');
        $peterUser = $this->user('supervisor', $peter);
        $peterUser->forceFill(['activated_at' => null])->save(); // active login, never activated
        $jane = $this->employee('Jane');
        $jane->forceFill(['primary_supervisor_id' => $peter->id, 'probation_status' => 'on_probation', 'probation_end_date' => now()->addMonth()->toDateString()])->save();
        $janeUser = $this->user('employee', $jane);

        $review = app(\App\Modules\Probation\Services\ProbationRouting::class)->open($jane->fresh(), notify: false);
        $this->assertSame('pending', $review->status);
        $this->assertSame($peter->id, (int) $review->primary_approver_id);
        $this->assertEmpty($review->stage_log);

        $this->actingAs($peterUser)->getJson('/api/probation/my-reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($peterUser)->postJson("/api/probation/reviews/{$review->id}/review", ['comment' => 'Good'])->assertOk();

        $data = $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision", ['decision' => 'confirm', 'comment' => 'Confirmed'])
            ->assertOk()->json('data');
        $this->assertSame('confirmed', $data['status']);
        $this->assertFalse($data['can_decide']);

        // The confirmation letter is in the personal file (HR view and the employee's own Documents).
        $this->assertNotEmpty($this->actingAs($this->hr)->getJson("/api/documents/employees/{$jane->id}")->assertOk()->json('letters'));
        $this->assertNotEmpty($this->actingAs($janeUser)->getJson('/api/documents/my-file')->assertOk()->json('letters'));
    }

    public function test_contract_staff_need_an_end_date(): void
    {
        $base = ['first_name' => 'Tom', 'last_name' => 'Test', 'date_of_joining' => now()->toDateString()];

        $this->actingAs($this->hr)->postJson('/api/employees', $base + ['contract_type' => 'contract'])
            ->assertStatus(422)->assertJsonValidationErrors(['contract_end_date', 'branch_id', 'national_id']);

        $this->actingAs($this->hr)->postJson('/api/employees', $base + ['contract_type' => 'permanent'])
            ->assertStatus(422)->assertJsonMissingValidationErrors(['contract_end_date']);
    }
}
