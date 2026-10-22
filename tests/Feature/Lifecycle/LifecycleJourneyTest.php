<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Modules\Employees\Events\EmployeeExited;
use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Posting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Lifecycle journeys: document permissions, offer → employee, probation stages / terminate / extend. */
class LifecycleJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    private Branch $branch;

    private Department $department;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->hr = $this->user(['hr-admin'], 'Hannah');
        $this->branch = Branch::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Nairobi']);
        $this->department = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Operations']);
        $this->position = Position::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => 'Analyst']);
    }

    private function user(array $roles, string $name, ?Employee $employee = null): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => $name, 'email' => uniqid(strtolower($name)).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);
        if ($employee) {
            $employee->forceFill(['user_id' => $user->id])->save();
        }

        return $user->fresh();
    }

    private function employee(string $name, array $attrs = []): Employee
    {
        $e = new Employee(['first_name' => $name, 'last_name' => 'Test', 'phone' => '07'.rand(10000000, 99999999), 'employee_number' => uniqid('E'),
            'gender' => 'male', 'date_of_joining' => $attrs['date_of_joining'] ?? now()->subMonths(2)->toDateString(),
            'branch_id' => $this->branch->id, 'department_id' => $this->department->id, 'position_id' => $this->position->id] + $attrs);
        $e->company_id = $this->company->id;
        $e->status = 'active';
        $e->save();

        return $e->fresh();
    }

    public function test_employee_cannot_list_or_issue_letters_and_contracts_but_can_download_own(): void
    {
        $me = $this->employee('Eve');
        $employee = $this->user(['employee'], 'Eve', $me);
        $other = $this->employee('Otto');

        $this->actingAs($employee)->getJson('/api/employees/letters')->assertForbidden();
        $this->actingAs($employee)->getJson('/api/employees/contracts')->assertForbidden();
        $this->actingAs($employee)->postJson('/api/employees/letters', ['employee_id' => $other->id, 'template' => 'warning', 'notes' => 'x'])->assertForbidden();
        $this->actingAs($employee)->postJson('/api/employees/contracts', ['employee_id' => $me->id, 'contract_type' => 'permanent', 'start_date' => '2026-01-01'])->assertForbidden();

        $contract = $this->actingAs($this->hr)->postJson('/api/employees/contracts', ['employee_id' => $me->id, 'contract_type' => 'permanent', 'start_date' => '2026-01-01'])
            ->assertCreated()->json('data');
        $this->actingAs($employee)->putJson("/api/employees/contracts/{$contract['id']}", ['notes' => 'hack'])->assertForbidden();
        $this->actingAs($this->hr)->getJson('/api/employees/contracts')->assertOk();
        $this->actingAs($employee)->get("/api/employees/contracts/{$contract['id']}/pdf")->assertOk();
    }

    public function test_accepted_offer_converts_to_employee_through_onboarding(): void
    {
        $posting = new Posting(['title' => 'Analyst', 'department_id' => $this->department->id, 'location' => 'Nairobi', 'status' => 'published', 'slug' => 'analyst-x1']);
        $posting->company_id = $this->company->id;
        $posting->save();
        $applicant = new Applicant(['posting_id' => $posting->id, 'name' => 'Jane Wanjiru Doe', 'email' => 'jane@cand.test', 'phone' => '0711000111', 'stage' => 'offer', 'applied_at' => now()]);
        $applicant->company_id = $this->company->id;
        $applicant->save();
        $offer = new Offer(['applicant_id' => $applicant->id, 'offered_salary' => 120000, 'start_date' => now()->addWeek()->toDateString(), 'expiry_date' => now()->addWeeks(2)->toDateString(), 'status' => 'draft']);
        $offer->company_id = $this->company->id;
        $offer->save();

        // Offer workflow: draft → sent → accepted (no skipping).
        $this->actingAs($this->hr)->postJson("/api/recruitment/offers/{$offer->id}/status", ['status' => 'accepted'])->assertStatus(422);
        $this->actingAs($this->hr)->postJson("/api/recruitment/offers/{$offer->id}/status", ['status' => 'sent'])->assertOk();
        $this->actingAs($this->hr)->postJson("/api/recruitment/offers/{$offer->id}/status", ['status' => 'accepted'])->assertOk();
        $this->assertSame('hired', $applicant->fresh()->stage);

        $prefill = $this->actingAs($this->hr)->getJson("/api/recruitment/applicants/{$applicant->id}/conversion")->assertOk()->json('data');
        $this->assertSame('Jane', $prefill['first_name']);
        $this->assertSame('Doe', $prefill['last_name']);
        $this->assertSame($this->department->id, $prefill['department_id']);
        $this->assertSame($this->branch->id, $prefill['branch_id']);
        $this->assertEquals(120000, $prefill['gross_salary']);

        $payload = array_merge($prefill, [
            'gender' => 'female', 'date_of_birth' => '1995-05-05', 'national_id' => '12345678', 'position_id' => $this->position->id,
            'next_of_kin' => [['name' => 'John Doe', 'relationship' => 'father', 'phone' => '0722000222']],
        ]);
        $id = $this->actingAs($this->hr)->postJson('/api/employees', $payload)->assertCreated()->json('data.id');

        $this->assertSame($id, $applicant->fresh()->employee_id);
        $this->assertEquals(120000, \App\Modules\Employees\Models\EmployeeContract::withoutGlobalScopes()->where('employee_id', $id)->where('status', 'active')->value('gross_salary'));
        // Converting twice is refused.
        $this->actingAs($this->hr)->postJson('/api/employees', $payload)->assertStatus(422);
    }

    public function test_requisition_must_be_approved_by_someone_else_before_posting(): void
    {
        $other = $this->user(['hr-admin'], 'Henry');
        $req = $this->actingAs($this->hr)->postJson('/api/recruitment/requisitions', ['position_id' => $this->position->id, 'department_id' => $this->department->id, 'vacancies' => 1, 'type' => 'permanent'])
            ->assertCreated()->json('data');
        $this->actingAs($this->hr)->postJson('/api/recruitment/postings', ['title' => 'Analyst', 'requisition_id' => $req['id']])->assertStatus(422);
        $this->actingAs($this->hr)->postJson("/api/recruitment/requisitions/{$req['id']}/approve")->assertForbidden();
        $this->actingAs($other)->postJson("/api/recruitment/requisitions/{$req['id']}/approve")->assertOk();
        $this->actingAs($this->hr)->postJson('/api/recruitment/postings', ['title' => 'Analyst', 'requisition_id' => $req['id']])->assertCreated();
    }

    /** Built-in chain with a reachable supervisor only: secondary and HOO are skipped automatically. */
    private function probationer(): array
    {
        $boss = $this->employee('Boss', ['date_of_joining' => '2020-01-01']);
        $bossUser = $this->user(['supervisor'], 'Boss', $boss);
        $staff = $this->employee('Pete', ['primary_supervisor_id' => $boss->id, 'probation_months' => 3,
            'probation_status' => 'on_probation', 'probation_end_date' => now()->addMonth()->toDateString()]);
        $this->assertSame('on_probation', $staff->probation_status);
        $review = app(ProbationRouting::class)->open($staff, notify: false);

        return [$staff, $bossUser, $review];
    }

    public function test_probation_stages_cannot_be_skipped_and_terminate_exits_the_employee(): void
    {
        Event::fake([EmployeeExited::class]);
        [$staff, $bossUser, $review] = $this->probationer();
        $this->assertSame('pending', $review->status);

        // HR can neither decide early nor review for the supervisor.
        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision", ['decision' => 'confirm', 'comment' => 'ok'])->assertStatus(422);
        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/review", ['comment' => 'skip'])->assertForbidden();

        $this->actingAs($bossUser)->getJson('/api/probation/my-reviews')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($bossUser)->postJson("/api/probation/reviews/{$review->id}/review", ['comment' => 'Not meeting targets'])->assertOk();
        $review->refresh();
        $this->assertSame('hr_review', $review->status, 'secondary + HOO have nobody reachable and are skipped');
        $this->assertCount(3, $review->stage_log);

        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision", ['decision' => 'terminate', 'comment' => 'Terminated'])->assertOk();
        $staff->refresh();
        $this->assertSame('exited', $staff->status);
        $this->assertSame('termination', $staff->exitRecord->reason);
        Event::assertDispatched(EmployeeExited::class, fn ($e) => $e->employee->id === $staff->id);
    }

    public function test_extended_probation_stays_visible_and_starts_a_new_cycle(): void
    {
        [$staff, $bossUser, $review] = $this->probationer();
        $this->actingAs($bossUser)->postJson("/api/probation/reviews/{$review->id}/review", ['comment' => 'Needs more time'])->assertOk();
        $newEnd = now()->addMonths(2)->toDateString();
        $this->actingAs($this->hr)->postJson("/api/probation/reviews/{$review->id}/hr-decision", ['decision' => 'extend', 'comment' => 'Extend', 'extension_end_date' => $newEnd])->assertOk();

        $staff->refresh();
        $this->assertSame('extended', $staff->probation_status);
        $this->assertSame($newEnd, $staff->probation_end_date->toDateString());

        $list = $this->actingAs($this->hr)->getJson('/api/probation')->assertOk()->json('data');
        $this->assertContains($staff->id, array_column($list, 'id'));

        $next = ProbationReview::withoutGlobalScopes()->where('employee_id', $staff->id)->latest('id')->first();
        $this->assertNotSame($review->id, $next->id);
        $this->assertSame(2, $next->cycle);
        $this->assertSame($newEnd, $next->probation_end_date->toDateString());
        $this->assertSame('pending', $next->status);
    }

    public function test_exit_reroutes_pending_probation_review_immediately(): void
    {
        [$staff, $bossUser, $review] = $this->probationer();
        $boss = $bossUser->employee;
        $this->assertSame($boss->id, $review->primary_approver_id);

        $this->actingAs($this->hr)->postJson("/api/employees/{$boss->id}/exit", ['exit_date' => now()->toDateString(), 'reason' => 'resignation'])->assertCreated();
        $review->refresh();
        $this->assertNotSame($boss->id, $review->primary_approver_id);
    }
}
