<?php

namespace Tests\Feature\Performance;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Compliance\Models\DisciplinaryCase;
use App\Modules\Compliance\Models\Grievance;
use App\Modules\Compliance\Notifications\ComplianceNotification;
use App\Modules\Performance\Models\CoachingSession;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Pip;
use App\Modules\Performance\Models\Unit;
use App\Modules\Performance\Services\GoalWorkflow;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\SetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Performance, engagement and compliance journeys: KPI plans + approvals, coaching / PIP, recognition, grievances, anonymous comments. */
class PerformanceJourneyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        PerformanceAccess::flush();
        GoalWorkflow::flushChains();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        SetupService::ensureDefaults($this->company->id);
    }

    /** @param  array<int, string>  $roles */
    private function person(array $roles, ?Employee $supervisor = null, string $name = 'Test'): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => "{$name} ".implode('-', $roles), 'email' => uniqid($roles[0]).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);
        $employee = new Employee(['first_name' => $name, 'last_name' => ucfirst($roles[0]), 'phone' => '0712345678', 'employee_number' => uniqid()]);
        $employee->company_id = $this->company->id;
        $employee->user_id = $user->id;
        $employee->status = 'active';
        $employee->primary_supervisor_id = $supervisor?->id;
        $employee->save();

        return $user->fresh();
    }

    /** @return array{0: User, 1: User, 2: User} hr, supervisor, employee */
    private function team(): array
    {
        $hr = $this->person(['hr-admin', 'employee'], null, 'Hana');
        $sup = $this->person(['supervisor', 'employee'], $hr->employee, 'Sam');
        $emp = $this->person(['employee'], $sup->employee, 'Eve');

        return [$hr, $sup, $emp];
    }

    private function cycle(): Cycle
    {
        $cycle = new Cycle(['name' => 'FY 2026', 'start_date' => now()->startOfYear(), 'end_date' => now()->endOfYear()]);
        $cycle->company_id = $this->company->id;
        $cycle->status = 'active';
        $cycle->save();

        return $cycle;
    }

    public function test_supervisor_plans_a_kpi_and_approves_it_from_the_inbox(): void
    {
        [, $sup, $emp] = $this->team();
        $goal = $this->submittedGoal($sup, $emp);

        $this->actingAs($sup)->getJson('/api/performance/approvals')->assertOk()->assertJsonPath('data.0.id', $goal);
        $inbox = (new \App\Modules\Performance\Dashboard\KpiApprovalProvider)->pendingFor($sup);
        $this->assertSame("/performance/goals/{$goal}/approve", $inbox[0]['approve_endpoint']);

        $this->postJson("/api/performance/goals/{$goal}/approve", ['comment' => 'Good'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(0, (new \App\Modules\Performance\Dashboard\KpiApprovalProvider)->countFor($sup));
    }

    /** Supervisor builds a plan (focus area, objective, KPI), cascades it; the employee accepts and submits. */
    private function submittedGoal(User $sup, User $emp): int
    {
        $cycle = $this->cycle();
        $unit = Unit::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $plan = $this->actingAs($sup)->postJson('/api/performance/plans', ['name' => 'Sales plan', 'cycle_id' => $cycle->id])
            ->assertCreated()->json('data.id');
        $area = $this->postJson("/api/performance/plans/{$plan}/focus-areas", ['name' => 'Capital'])->assertCreated()->json('data.id');
        $objective = $this->postJson("/api/performance/focus-areas/{$area}/objectives", ['name' => 'Grow revenue', 'weight' => 100])
            ->assertCreated()->json('data.id');
        $kpi = $this->postJson("/api/performance/objectives/{$objective}/kpis", [
            'name' => 'Revenue', 'type' => 'quantitative', 'unit_id' => $unit->id, 'target' => 1000, 'due_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data.id');
        $this->getJson("/api/performance/plans/{$plan}")->assertOk()->assertJsonPath('data.focus_areas.0.objectives.0.kpis.0.id', $kpi);

        $goal = $this->postJson("/api/performance/kpis/{$kpi}/assign", [
            'mode' => 'cascade', 'split' => 'equal', 'assignees' => [['employee_id' => $emp->employee->id]],
        ])->assertCreated()->json('data.0.id');

        $this->actingAs($emp)->postJson("/api/performance/goals/{$goal}/accept")->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->postJson("/api/performance/goals/{$goal}/submit", ['achieved' => 900, 'comment' => 'Done'])->assertOk()->assertJsonPath('data.status', 'submitted');

        return $goal;
    }

    public function test_configured_kpi_review_chain_routes_approval_step_by_step(): void
    {
        [$hr, $sup, $emp] = $this->team();
        $wf = new \App\Modules\Organization\Models\Workflow(['name' => 'KPI review', 'trigger' => 'kpi_review', 'is_active' => true,
            'steps' => [['type' => 'supervisor'], ['type' => 'role', 'role' => 'hr-admin', 'label' => 'HR']]]);
        $wf->forceFill(['company_id' => $this->company->id])->save();
        GoalWorkflow::flushChains();

        $goal = $this->submittedGoal($sup, $emp);
        $provider = new \App\Modules\Performance\Dashboard\KpiApprovalProvider;
        $this->assertSame(1, $provider->countFor($sup));
        $this->assertSame(0, $provider->countFor($hr));

        $this->actingAs($sup)->postJson("/api/performance/goals/{$goal}/approve")->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->assertSame(0, $provider->countFor($sup));
        $this->assertSame(1, $provider->countFor($hr));
        $this->actingAs($emp)->postJson("/api/performance/goals/{$goal}/approve")->assertForbidden();

        $this->actingAs($hr)->postJson("/api/performance/goals/{$goal}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_warning_generates_the_employees_warning_letter_and_notifies_the_employee(): void
    {
        [$hr, , $emp] = $this->team();

        $res = $this->actingAs($hr)->postJson('/api/compliance/warnings', [
            'employee_id' => $emp->employee->id, 'level' => 'written', 'reason' => 'Repeated lateness in October.',
        ])->assertCreated();
        $letterId = $res->json('data.letter_id');
        $this->assertNotNull($letterId);
        $this->assertSame("/api/employees/letters/{$letterId}/download", $res->json('pdf_url'));
        $this->assertDatabaseHas('employee_letters', ['id' => $letterId, 'template' => 'warning', 'employee_id' => $emp->employee->id]);
        Notification::assertSentTo($emp, ComplianceNotification::class, fn ($n) => $n->title === 'Warning letter issued');

        // Generating again returns the same letter.
        $this->postJson('/api/compliance/warnings/'.$res->json('data.id').'/letter')->assertOk()->assertJsonPath('letter_id', $letterId);
    }

    public function test_employee_opens_and_answers_only_their_own_self_assessment(): void
    {
        [, $sup, $emp] = $this->team();
        $other = $this->person(['employee'], $sup->employee, 'Olga');
        $this->cycle();

        $id = $this->actingAs($sup)->postJson('/api/performance/reviews', ['employee_id' => $emp->employee->id, 'due_date' => now()->addWeek()->toDateString()])
            ->assertCreated()->json('data.id');
        $question = \App\Modules\Performance\Models\Questionnaire::findOrFail($id)->questions()->value('perf_questions.id');

        $this->actingAs($emp)->getJson("/api/performance/reviews/{$id}")->assertOk();
        $this->postJson("/api/performance/reviews/{$id}/respond", ['ratings' => [$question => 4]])->assertOk();
        $this->assertDatabaseHas('perf_questionnaire_responses', ['questionnaire_id' => $id, 'question_id' => $question, 'rater_type' => 'self', 'rating' => 4]);

        $this->actingAs($other)->getJson("/api/performance/reviews/{$id}")->assertForbidden();
        $this->postJson("/api/performance/reviews/{$id}/respond", ['ratings' => [$question => 1]])->assertForbidden();
    }

    public function test_supervisor_performance_management_endpoints_return_200(): void
    {
        [, $sup] = $this->team();
        $this->cycle();
        foreach (['cycles', 'setup', 'team-goals', 'approvals', 'team-overview', 'plans', 'appraisals', 'coaching', 'coaching/points',
            'coaching/sessions', 'coaching/pips', 'reviews', 'recognition'] as $path) {
            $this->actingAs($sup)->getJson("/api/performance/{$path}")->assertOk();
        }
        // Cycle writes stay with HR.
        $this->actingAs($sup)->postJson('/api/performance/cycles', ['name' => 'X'])->assertForbidden();
    }

    public function test_coaching_session_escalates_to_a_pip_and_an_escalated_pip_opens_a_disciplinary_case(): void
    {
        [$hr, $sup, $emp] = $this->team();

        $session = $this->actingAs($sup)->postJson('/api/performance/coaching/sessions', [
            'employee_id' => $emp->employee->id, 'session_date' => now()->toDateString(), 'topic' => 'Missed targets',
        ])->assertCreated()->json('data.id');

        $pip = $this->postJson("/api/performance/coaching/sessions/{$session}/escalate", [
            'concern' => 'Sales below target', 'targets' => 'Reach 80%', 'action_plan' => 'Weekly coaching',
            'start_date' => now()->toDateString(), 'end_date' => now()->addMonths(2)->toDateString(), 'review_frequency' => 'weekly',
        ])->assertCreated()->json('data.id');
        $this->assertSame('escalated', CoachingSession::find($session)->outcome);

        $this->postJson("/api/performance/coaching/pips/{$pip}/checkin", [
            'meeting_date' => now()->toDateString(), 'progress_rating' => 2, 'recommendation' => 'escalate',
        ])->assertCreated();
        $this->postJson("/api/performance/coaching/pips/{$pip}/close", ['outcome' => 'escalated', 'comment' => 'No improvement'])
            ->assertOk()->assertJsonPath('disciplinary_case.status', 'open');

        $case = DisciplinaryCase::where('pip_id', $pip)->first();
        $this->assertNotNull($case);
        $this->assertSame($emp->employee->id, $case->employee_id);
        $this->assertSame('escalated', Pip::find($pip)->status);
        Notification::assertSentTo($hr, ComplianceNotification::class);

        // The employee sees the PIP and the linked case in self-service.
        $this->actingAs($emp)->getJson('/api/performance/my-coaching')->assertOk()
            ->assertJsonPath('pips.0.id', $pip)->assertJsonPath('pips.0.disciplinary_case.id', $case->id);
    }

    public function test_employee_recognises_a_colleague_but_not_themselves(): void
    {
        [, $sup, $emp] = $this->team();

        $this->actingAs($emp)->postJson('/api/performance/recognition', [
            'employee_id' => $emp->employee->id, 'title' => 'Me', 'message' => 'I did a great job this week.',
        ])->assertStatus(422);
        $this->postJson('/api/performance/recognition', [
            'employee_id' => $sup->employee->id, 'title' => 'Great mentor', 'message' => 'Thanks for all the coaching this quarter.',
        ])->assertCreated()->assertJsonPath('data.title', 'Great mentor');

        $this->getJson('/api/performance/recognition/my')->assertOk()->assertJsonCount(1, 'given')->assertJsonCount(0, 'received');
        $this->actingAs($sup)->getJson('/api/performance/recognition/my')->assertOk()->assertJsonCount(1, 'received');
    }

    public function test_learning_self_service_catalogue_enrol_and_progress(): void
    {
        [, , $emp] = $this->team();
        $course = new \App\Modules\Performance\Models\Course(['name' => 'Excel basics', 'is_active' => true]);
        $course->company_id = $this->company->id;
        $course->save();

        $this->actingAs($emp)->getJson('/api/performance/learning/catalogue')->assertOk()->assertJsonPath('data.0.can_enrol', true);
        $enrollment = $this->postJson("/api/performance/learning/catalogue/{$course->id}/enrol")->assertCreated()->json('data.id');
        $this->postJson("/api/performance/learning/catalogue/{$course->id}/enrol")->assertStatus(422);
        $this->putJson("/api/performance/learning/my/{$enrollment}", ['progress' => 100])->assertOk()->assertJsonPath('data.status', 'completed');
    }

    public function test_my_grievances_including_anonymous_ones(): void
    {
        [$hr, , $emp] = $this->team();

        $this->actingAs($emp)->postJson('/api/my/grievances', ['subject' => 'Unsafe shift', 'description' => 'Night shifts have no security.'])
            ->assertCreated()->assertJsonPath('data.anonymous', false);
        $this->postJson('/api/my/grievances', ['subject' => 'Harassment', 'description' => 'A manager shouts at staff daily.', 'anonymous' => true, 'category' => 'harassment'])
            ->assertCreated()->assertJsonPath('data.anonymous', true);
        $this->getJson('/api/my/grievances')->assertOk()->assertJsonCount(2, 'data');

        $this->assertNull(Grievance::where('subject', 'Harassment')->value('employee_id'));
        $hrView = $this->actingAs($hr)->getJson('/api/compliance/grievances')->assertOk();
        $this->assertStringNotContainsString('author_hash', $hrView->getContent());
        $anon = collect($hrView->json('data'))->firstWhere('subject', 'Harassment');
        $this->assertNull($anon['employee_id']);
        $this->assertSame('Anonymous', $anon['employee_name']);
        Notification::assertSentTo($hr, ComplianceNotification::class);

        // Status updates reach the anonymous author.
        $this->putJson("/api/compliance/grievances/{$anon['id']}", ['status' => 'resolved', 'resolution' => 'Manager warned.'])->assertOk();
        Notification::assertSentTo($emp, ComplianceNotification::class, fn ($n) => str_contains($n->message, 'resolved'));

        // Other employees cannot see it.
        $this->actingAs($this->person(['employee'], null, 'Other'))->getJson('/api/my/grievances')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_anonymous_comments_reach_their_author_without_revealing_them_to_hr(): void
    {
        [$hr, , $emp] = $this->team();

        // "anonymous" may be omitted.
        $this->actingAs($emp)->postJson('/api/engagement/comments', ['category' => 'tools', 'body' => 'The laptops are very slow.'])->assertCreated();
        $id = $this->postJson('/api/engagement/comments', ['category' => 'supervision', 'body' => 'My supervisor plays favourites.', 'anonymous' => true])
            ->assertCreated()->json('data.id');

        $hrList = $this->actingAs($hr)->getJson('/api/engagement/comments')->assertOk();
        $row = collect($hrList->json('data.data'))->firstWhere('id', $id);
        $this->assertNull($row['employee']);
        $this->assertStringNotContainsString('author_hash', $hrList->getContent());
        $this->assertStringNotContainsString($emp->name, json_encode($row));

        $this->postJson("/api/engagement/comments/{$id}/reply", ['body' => 'Thanks, we will look into it.'])->assertOk();

        $mine = $this->actingAs($emp)->getJson('/api/engagement/comments/mine')->assertOk();
        $thread = collect($mine->json('data'))->firstWhere('id', $id);
        $this->assertNotNull($thread);
        $this->assertSame('Thanks, we will look into it.', $thread['replies'][0]['body']);

        $this->postJson("/api/engagement/comments/{$id}/respond", ['body' => 'It happened again today.'])->assertOk();
        $this->actingAs($this->person(['employee'], null, 'Other'))->postJson("/api/engagement/comments/{$id}/respond", ['body' => 'Hijack'])->assertForbidden();

        $after = $this->actingAs($hr)->getJson('/api/engagement/comments')->assertOk();
        $row = collect($after->json('data.data'))->firstWhere('id', $id);
        $this->assertSame('Anonymous', $row['replies'][1]['author']);
        $this->assertStringNotContainsString($emp->name, json_encode($row));
        $this->assertDatabaseHas('engagement_replies', ['comment_id' => $id, 'from_author' => true, 'user_id' => null]);
    }
}
