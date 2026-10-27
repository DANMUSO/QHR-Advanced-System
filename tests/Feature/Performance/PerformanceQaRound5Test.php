<?php

namespace Tests\Feature\Performance;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Unit;
use App\Modules\Performance\Services\GoalWorkflow;
use App\Modules\Performance\Services\PerformanceAccess;
use App\Modules\Performance\Services\SetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 5: 9-box from approved KPI results, KPI due dates inside the cycle, plan creator, succession incumbent. */
class PerformanceQaRound5Test extends TestCase
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

    private function person(array $roles, ?Employee $supervisor = null, string $name = 'Test', bool $withEmployee = true): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => "{$name} ".implode('-', $roles), 'email' => uniqid($roles[0]).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);
        if ($withEmployee) {
            $employee = new Employee(['first_name' => $name, 'last_name' => ucfirst($roles[0]), 'phone' => '0712345678', 'employee_number' => uniqid()]);
            $employee->company_id = $this->company->id;
            $employee->user_id = $user->id;
            $employee->status = 'active';
            $employee->primary_supervisor_id = $supervisor?->id;
            $employee->save();
        }

        return $user->fresh();
    }

    private function cycle(): Cycle
    {
        $cycle = new Cycle(['name' => 'H2', 'start_date' => now()->subMonths(3)->toDateString(), 'end_date' => now()->addMonths(2)->toDateString()]);
        $cycle->company_id = $this->company->id;
        $cycle->status = 'active';
        $cycle->save();

        return $cycle;
    }

    /** @return array{0:int,1:int} plan id, kpi id */
    private function planWithKpi(User $author, Cycle $cycle): array
    {
        $unit = Unit::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $plan = $this->actingAs($author)->postJson('/api/performance/plans', ['name' => 'Plan', 'cycle_id' => $cycle->id])->assertCreated()->json('data.id');
        $area = $this->postJson("/api/performance/plans/{$plan}/focus-areas", ['name' => 'Capital'])->assertCreated()->json('data.id');
        $objective = $this->postJson("/api/performance/focus-areas/{$area}/objectives", ['name' => 'Grow', 'weight' => 100])->assertCreated()->json('data.id');
        $kpi = $this->postJson("/api/performance/objectives/{$objective}/kpis", [
            'name' => 'Revenue', 'type' => 'quantitative', 'unit_id' => $unit->id, 'target' => 1000, 'due_date' => now()->addMonth()->toDateString(),
        ])->assertCreated()->json('data.id');

        return [$plan, $kpi];
    }

    public function test_kpi_due_date_must_fall_within_the_cycle(): void
    {
        $hr = $this->person(['hr-admin', 'employee'], null, 'Hana');
        $cycle = $this->cycle();
        [$plan, $kpi] = $this->planWithKpi($hr, $cycle);
        $objective = \App\Modules\Performance\Models\Kpi::find($kpi)->objective_id;
        $unit = Unit::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();

        $this->postJson("/api/performance/objectives/{$objective}/kpis", [
            'name' => 'Late', 'type' => 'quantitative', 'unit_id' => $unit->id, 'target' => 10, 'due_date' => now()->addMonths(5)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
        $this->putJson("/api/performance/kpis/{$kpi}", ['due_date' => now()->subMonths(4)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('due_date');
        $emp = $this->person(['employee'], $hr->employee, 'Eve');
        $this->actingAs($hr)->postJson("/api/performance/kpis/{$kpi}/assign", [
            'mode' => 'cascade', 'split' => 'equal', 'assignees' => [['employee_id' => $emp->employee->id]], 'due_date' => now()->addMonths(6)->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('due_date');
    }

    public function test_plan_created_by_an_admin_without_employee_record_shows_the_user(): void
    {
        $admin = $this->person(['hr-admin'], null, 'Root', false);
        $cycle = $this->cycle();
        $this->actingAs($admin)->postJson('/api/performance/plans', ['name' => 'Plan', 'cycle_id' => $cycle->id])
            ->assertCreated()->assertJsonPath('data.created_by', $admin->name);
        $this->getJson('/api/performance/plans')->assertOk()->assertJsonPath('data.0.created_by', $admin->name);
    }

    public function test_nine_box_is_populated_from_an_approved_kpi_result(): void
    {
        $hr = $this->person(['hr-admin', 'employee'], null, 'Hana');
        $emp = $this->person(['employee'], $hr->employee, 'Juma');
        $cycle = $this->cycle();
        [, $kpi] = $this->planWithKpi($hr, $cycle);

        $goal = $this->actingAs($hr)->postJson("/api/performance/kpis/{$kpi}/assign", [
            'mode' => 'cascade', 'split' => 'equal', 'assignees' => [['employee_id' => $emp->employee->id]],
        ])->assertCreated()->json('data.0.id');
        $this->actingAs($emp)->postJson("/api/performance/goals/{$goal}/accept")->assertOk();
        $this->postJson("/api/performance/goals/{$goal}/submit", ['achieved' => 1200])->assertOk();
        $this->actingAs($hr)->postJson("/api/performance/goals/{$goal}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // No potential rating yet: listed as "potential not rated", not placed on a guessed box.
        $res = $this->getJson("/api/succession/9box?cycle_id={$cycle->id}")->assertOk();
        $this->assertSame([], $res->json('data'));
        $this->assertSame($emp->employee->id, $res->json('potential_not_rated.0.id'));
        $this->assertSame(3, $res->json('potential_not_rated.0.performance_box'));
        $this->assertSame('kpi', $res->json('potential_not_rated.0.score_source'));

        $this->putJson('/api/succession/9box/potential', ['cycle_id' => $cycle->id, 'employee_id' => $emp->employee->id, 'potential' => 3])->assertOk();
        $res = $this->getJson("/api/succession/9box?cycle_id={$cycle->id}")->assertOk();
        $this->assertSame($emp->employee->id, $res->json('data.0.id'));
        $this->assertSame(3, $res->json('data.0.potential_box'));
        $this->assertSame([], $res->json('potential_not_rated'));
    }

    public function test_succession_incumbent_defaults_to_the_position_holder(): void
    {
        $hr = $this->person(['hr-admin', 'employee'], null, 'Hana');
        $position = Position::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => 'Finance Manager']);
        $holder = $this->person(['employee'], null, 'Fiona')->employee;
        $holder->forceFill(['position_id' => $position->id])->save();

        $this->actingAs($hr)->getJson("/api/succession/positions/{$position->id}/holders")->assertOk()->assertJsonPath('data.0.id', $holder->id);
        $this->postJson('/api/succession', ['position_id' => $position->id, 'readiness' => 'ready_1_2_years', 'risk_level' => 'low'])
            ->assertCreated()->assertJsonPath('data.incumbent_id', $holder->id);
    }
}
