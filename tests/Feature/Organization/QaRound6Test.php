<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Payroll\Http\Controllers\SetupController;
use App\Modules\Payroll\Models\PayrollCalendar;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Support\PayrollDefaults;
use App\Modules\Payroll\Support\PayrollSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** QA round 6: payroll calendar, probation limits, readable errors, holidays, sick leave, activation sign-out. */
class QaRound6Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Six Ltd', 'code' => 'SIX', 'is_active' => true]);
    }

    private function user(string $role): User
    {
        $u = User::create(['company_id' => $this->company->id, 'name' => $role, 'email' => $role.'@six.test', 'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
        $u->assignRole($role);

        return $u->fresh();
    }

    public function test_calendar_dates_stay_in_their_month_with_cutoff_before_pay(): void
    {
        $officer = $this->user('payroll-officer');
        SetupController::ensureCalendar($this->company, 2026);
        $oct = PayrollCalendar::withoutGlobalScopes()->where('company_id', $this->company->id)->whereDate('period', '2026-10-01')->firstOrFail();

        $this->actingAs($officer)->putJson("/api/payroll/calendar/{$oct->id}", ['cutoff_date' => '2026-10-30', 'pay_date' => '2026-11-05'])
            ->assertStatus(422)->assertJsonValidationErrors('pay_date');
        $this->actingAs($officer)->putJson("/api/payroll/calendar/{$oct->id}", ['cutoff_date' => '2026-10-29', 'pay_date' => '2026-10-27'])
            ->assertStatus(422);
        $this->actingAs($officer)->putJson("/api/payroll/calendar/{$oct->id}", ['cutoff_date' => '2026-10-20', 'pay_date' => '2026-10-27'])->assertOk();
    }

    public function test_changing_pay_day_moves_months_not_yet_processed(): void
    {
        $officer = $this->user('payroll-officer');
        SetupController::ensureCalendar($this->company, (int) now()->year);
        $next = CarbonImmutable::now()->addMonthNoOverflow()->startOfMonth();

        $settings = $this->actingAs($officer)->getJson('/api/payroll/settings')->json('data');
        $this->actingAs($officer)->putJson('/api/payroll/settings', ['pay_day' => 25, 'cutoff_day' => 15] + $settings)->assertOk();

        $row = PayrollCalendar::withoutGlobalScopes()->where('company_id', $this->company->id)->whereDate('period', $next->toDateString())->first();
        if ($row) { // December has no "next month" row in this year's calendar
            $expected = $next->day(25);
            while ($expected->isWeekend()) {
                $expected = $expected->subDay();
            }
            $this->assertSame($expected->toDateString(), $row->pay_date->toDateString());
        }
        $this->actingAs($officer)->putJson('/api/payroll/settings', ['pay_day' => 10, 'cutoff_day' => 20] + $settings)
            ->assertStatus(422)->assertJsonValidationErrors('cutoff_day');
    }

    public function test_probation_follows_the_employment_act(): void
    {
        $hr = $this->user('hr-admin');
        $this->actingAs($hr)->postJson('/api/organization/levels', ['name' => 'Long', 'probation_months' => 13, 'probation_review_days' => 30])
            ->assertStatus(422)->assertJsonValidationErrors('probation_months');
        $this->actingAs($hr)->postJson('/api/organization/levels', ['name' => 'Standard', 'probation_months' => 6, 'probation_review_days' => 30])->assertCreated();
    }

    public function test_duplicate_structure_component_gives_a_readable_message(): void
    {
        $officer = $this->user('payroll-officer');
        PayrollDefaults::ensureComponents($this->company->id);
        $house = PayrollComponent::withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'HOUSE')->value('id');

        $errors = $this->actingAs($officer)->postJson('/api/payroll/structures', ['name' => 'Band A', 'basic_salary' => 50000,
            'components' => [['component_id' => $house, 'amount' => 5000], ['component_id' => $house, 'amount' => 5000]]])
            ->assertStatus(422)->json('errors');
        $messages = collect($errors)->flatten()->implode(' ');
        $this->assertStringContainsString('only be added once', $messages);
        $this->assertStringNotContainsString('components.0', $messages);
    }

    public function test_holiday_badge_counts_the_same_year_as_the_list_and_includes_idd_ul_fitr(): void
    {
        $hr = $this->user('hr-admin');
        Holiday::create(['company_id' => null, 'name' => 'Christmas Day', 'date' => '2026-12-25', 'recurring' => true]);
        Holiday::create(['company_id' => null, 'name' => 'Good Friday', 'date' => '2026-04-03', 'recurring' => false]);
        Holiday::create(['company_id' => null, 'name' => 'Good Friday', 'date' => '2027-03-26', 'recurring' => false]);

        $summary = $this->actingAs($hr)->getJson('/api/organization/summary?year=2026')->assertOk()->json('holidays');
        $list = $this->actingAs($hr)->getJson('/api/organization/holidays?year=2026')->assertOk()->json('data');
        $this->assertSame(count($list), $summary);
        $names = collect($list)->pluck('name');
        $this->assertContains('Idd-ul-Fitr', $names->all(), 'Idd-ul-Fitr is seeded as a national holiday');
        $this->assertSame(1, $names->filter(fn ($n) => $n === 'Good Friday')->count(), 'the 2027 Good Friday is not counted for 2026');
    }

    public function test_sick_leave_defaults_are_the_statutory_seven_plus_seven(): void
    {
        LeaveType::seedDefaults($this->company);
        $days = LeaveType::withoutGlobalScopes()->where('company_id', $this->company->id)->whereIn('code', ['SICK_FULL', 'SICK_HALF'])->pluck('days_per_year', 'code');
        $this->assertEquals(7, $days['SICK_FULL']);
        $this->assertEquals(7, $days['SICK_HALF']);
    }

    public function test_activating_an_account_signs_out_whoever_was_signed_in(): void
    {
        $admin = $this->user('hr-admin');
        $newcomer = User::create(['company_id' => $this->company->id, 'name' => 'New', 'email' => 'new@six.test', 'password' => 'x', 'is_active' => true]);
        $token = Password::broker('activations')->createToken($newcomer);

        $this->actingAs($admin)->withHeader('Referer', 'http://127.0.0.1:8000')->postJson('/api/auth/activate', [
            'token' => $token, 'email' => 'new@six.test', 'password' => 'Str0ng!Passw0rd#', 'password_confirmation' => 'Str0ng!Passw0rd#',
        ])->assertOk()->assertJsonPath('email', 'new@six.test');

        $this->assertFalse(Auth::guard('web')->check(), 'the previous session is signed out');
        $this->assertNotNull($newcomer->fresh()->activated_at);
    }
}
