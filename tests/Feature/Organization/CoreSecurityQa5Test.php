<?php

namespace Tests\Feature\Organization;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LoginActivity;
use App\Models\User;
use App\Modules\Documents\Models\CompanyDocument;
use App\Notifications\ActivateAccount;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * QA round 5 (core & security): segregation of duties for role grants, impersonation and activation links;
 * standalone approver accounts; complete sign-in logging; policy acknowledgement after viewing; position level.
 */
class CoreSecurityQa5Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        config(['hr.enforce_two_factor' => false]);
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
    }

    private function user(string|array $roles, bool $activated = true, ?int $companyId = -1): User
    {
        $user = User::create([
            'company_id' => $companyId === -1 ? $this->company->id : $companyId, 'name' => 'User '.implode('+', (array) $roles),
            'email' => uniqid('u').'@t.test', 'password' => 'Password@123', 'is_active' => true, 'activated_at' => $activated ? now() : null,
        ]);
        $user->assignRole((array) $roles);

        return $user->fresh();
    }

    /** A browser (stateful, session-backed) request, as the SPA makes: needed for sign-in and impersonation. */
    private function stateful(): static
    {
        return $this->withHeader('Referer', 'http://localhost/');
    }

    private function employee(User $user): Employee
    {
        $e = new Employee(['first_name' => 'Mary', 'last_name' => 'Test', 'phone' => '07'.random_int(10000000, 99999999),
            'employee_number' => uniqid('E'), 'national_id' => (string) random_int(10000000, 99999999), 'kra_pin' => 'A000000001Z']);
        $e->company_id = $this->company->id;
        $e->user_id = $user->id;
        $e->status = 'active';
        $e->gender = 'female';
        $e->save();

        return $e;
    }

    // ---- #37 segregation of duties --------------------------------------------------------------

    public function test_hr_can_only_grant_and_revoke_staff_roles(): void
    {
        $hr = $this->user('hr-admin');
        $staff = $this->user('employee');

        foreach (['finance', 'payroll-officer', 'hr-admin'] as $role) {
            $this->actingAs($hr)->putJson("/api/admin/users/{$staff->id}/roles", ['roles' => ['employee', $role]])->assertForbidden();
        }
        $this->assertFalse($staff->fresh()->hasRole('finance'));

        $this->actingAs($hr)->putJson("/api/admin/users/{$staff->id}/roles", ['roles' => ['employee', 'supervisor']])->assertOk();
        $this->assertTrue($staff->fresh()->hasRole('supervisor'));

        // Nor can HR remove a privileged role from someone, while leaving it untouched is fine.
        $finance = $this->user(['employee', 'finance']);
        $this->actingAs($hr)->putJson("/api/admin/users/{$finance->id}/roles", ['roles' => ['employee']])->assertForbidden();
        $this->actingAs($hr)->putJson("/api/admin/users/{$finance->id}/roles", ['roles' => ['employee', 'finance', 'supervisor']])->assertOk();

        // The detail payload locks those roles for HR, with the reason.
        $roles = collect($this->actingAs($hr)->getJson("/api/admin/users/{$staff->id}")->assertOk()->json('assignable_roles'))->keyBy('name');
        $this->assertTrue($roles['finance']['locked']);
        $this->assertNotEmpty($roles['finance']['locked_reason']);
        $this->assertFalse($roles['supervisor']['locked']);

        // HR cannot hand out payroll or claim-payment permissions directly either.
        $this->actingAs($hr)->putJson("/api/admin/users/{$staff->id}/permissions", ['permissions' => ['payroll.review']])->assertForbidden();

        // A super admin can.
        $super = $this->user('super-admin');
        $this->actingAs($super)->putJson("/api/admin/users/{$staff->id}/roles", ['roles' => ['employee', 'finance']])->assertOk();
        $this->assertTrue($staff->fresh()->hasRole('finance'));
    }

    public function test_hr_cannot_impersonate_privileged_users_or_users_with_extra_permissions(): void
    {
        $hr = $this->user('hr-admin');
        $finance = $this->user(['employee', 'finance']);
        $payroll = $this->user(['employee', 'payroll-officer']);

        $this->actingAs($hr)->postJson("/api/admin/impersonate/{$finance->id}")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/admin/impersonate/{$payroll->id}")->assertForbidden();
        $this->assertFalse($this->actingAs($hr)->getJson("/api/admin/users/{$finance->id}")->json('can_impersonate'));

        // A plain employee holding a permission HR lacks is out of reach too.
        $hrPerms = $hr->getAllPermissions()->pluck('name');
        $extra = Permission::whereNotIn('name', $hrPerms)->where('name', 'not like', 'payroll.%')
            ->whereNotIn('name', ['claims.pay', 'companies.manage', 'users.manage', 'users.impersonate'])->value('name');
        $this->assertNotNull($extra, 'expected a permission HR does not hold');
        $staff = $this->user('employee');
        $staff->givePermissionTo($extra);
        $this->actingAs($hr)->postJson("/api/admin/impersonate/{$staff->id}")->assertForbidden();

        // An ordinary employee can be impersonated by HR.
        $plain = $this->user('employee');
        if ($plain->getAllPermissions()->pluck('name')->diff($hrPerms)->isEmpty()) {
            $this->stateful()->actingAs($hr)->postJson("/api/admin/impersonate/{$plain->id}")->assertOk();
            $this->flushHeaders();
        }

        // A super admin may sign in as Finance.
        $super = $this->user('super-admin');
        $this->stateful()->actingAs($super)->postJson("/api/admin/impersonate/{$finance->id}")->assertOk();
    }

    public function test_activation_links_for_privileged_accounts_are_super_admin_only(): void
    {
        $this->app['env'] = 'local';
        $hr = $this->user('hr-admin');
        $finance = $this->user(['employee', 'finance'], activated: false);
        $staff = $this->user('employee', activated: false);

        $this->actingAs($hr)->postJson("/api/admin/users/{$finance->id}/activation-link")->assertForbidden();
        $this->actingAs($hr)->postJson("/api/admin/users/{$finance->id}/resend-activation")->assertForbidden();
        $detail = $this->actingAs($hr)->getJson("/api/admin/users/{$finance->id}")->assertOk();
        $this->assertFalse($detail->json('can_copy_activation_link'));
        $this->assertFalse($detail->json('can_resend_activation'));

        $this->actingAs($hr)->postJson("/api/admin/users/{$staff->id}/activation-link")->assertOk();
        $this->actingAs($hr)->postJson("/api/admin/users/{$staff->id}/resend-activation")->assertOk();

        $super = $this->user('super-admin');
        $this->actingAs($super)->postJson("/api/admin/users/{$finance->id}/activation-link")->assertOk();
    }

    public function test_second_impersonation_explains_how_to_return_first(): void
    {
        $super = $this->user('super-admin');
        $a = $this->user('employee');
        $b = $this->user('employee');

        // Signed in as A on behalf of the super admin, then asking to impersonate B.
        $this->stateful()->withSession(['impersonator_id' => $super->id])->actingAs($a)->postJson("/api/admin/impersonate/{$b->id}")
            ->assertStatus(422)->assertJsonFragment(['message' => "You are already signed in as {$a->name} (impersonated by {$super->name}). Use \"Return to my account\" in the banner at the top before impersonating someone else."]);
    }

    // ---- #23 standalone approver ------------------------------------------------------------------

    public function test_super_admin_creates_a_user_without_an_employee_record(): void
    {
        $super = $this->user('super-admin', companyId: null);
        $payload = ['name' => 'Ext Accountant', 'email' => 'acc@ext.test', 'company_id' => $this->company->id, 'roles' => ['finance']];

        $this->actingAs($this->user('hr-admin'))->postJson('/api/admin/users', $payload)->assertForbidden();

        $id = $this->actingAs($super)->postJson('/api/admin/users', $payload)->assertCreated()
            ->assertJsonPath('data.employee', null)->json('data.id');
        $user = User::find($id);
        $this->assertTrue($user->hasRole('finance'));
        $this->assertSame($this->company->id, $user->company_id);
        $this->assertNull($user->activated_at);
        Notification::assertSentTo($user, ActivateAccount::class);

        $this->actingAs($super)->postJson('/api/admin/users', $payload)->assertStatus(422)->assertJsonValidationErrors('email');
        $this->actingAs($super)->postJson('/api/admin/users', [...$payload, 'email' => 'x@ext.test', 'roles' => []])
            ->assertStatus(422)->assertJsonValidationErrors('roles');
    }

    // ---- #19 sign-in log ---------------------------------------------------------------------------

    public function test_sign_in_log_records_logins_failures_and_impersonation(): void
    {
        $staff = $this->user('employee');

        $this->stateful()->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'Password@123'])->assertOk();
        $this->assertDatabaseHas('login_activities', ['user_id' => $staff->id, 'event' => 'failed', 'company_id' => $this->company->id]);
        $this->assertDatabaseHas('login_activities', ['user_id' => $staff->id, 'event' => 'login', 'company_id' => $this->company->id]);
        $this->assertNotNull($staff->fresh()->last_login_at);

        $staff->forceFill(['is_active' => false])->save();
        $this->postJson('/api/auth/login', ['email' => $staff->email, 'password' => 'Password@123'])->assertForbidden();
        $this->assertSame(2, LoginActivity::where('user_id', $staff->id)->where('event', 'failed')->count());
        $staff->forceFill(['is_active' => true])->save();

        // A platform super admin (no company) impersonates a company user: logged against that company.
        $super = $this->user('super-admin', companyId: null);
        $this->flushSession();
        $this->actingAs($super, 'web')->postJson("/api/admin/impersonate/{$staff->id}")->assertOk();
        $this->withSession(['impersonator_id' => $super->id])->actingAs($staff)->postJson('/api/auth/impersonate/stop')->assertOk();
        $this->flushHeaders();
        $this->assertDatabaseHas('login_activities', ['user_id' => $staff->id, 'event' => 'impersonation_started', 'impersonator_id' => $super->id, 'company_id' => $this->company->id]);
        $this->assertDatabaseHas('login_activities', ['user_id' => $staff->id, 'event' => 'impersonation_stopped', 'impersonator_id' => $super->id]);

        // The company-scoped log (super admin with the company selected) shows all of it.
        $events = collect($this->actingAs($super)->withHeader('X-Company-Id', (string) $this->company->id)
            ->getJson('/api/admin/login-activities')->assertOk()->json('data'))->pluck('event');
        foreach (['login', 'failed', 'impersonation_started', 'impersonation_stopped'] as $e) {
            $this->assertContains($e, $events->all());
        }
        // HR of the company sees it too.
        $hrEvents = collect($this->actingAs($this->user('hr-admin'))->withHeaders(['X-Company-Id' => ''])
            ->getJson('/api/admin/login-activities')->assertOk()->json('data'))->pluck('event');
        $this->assertContains('impersonation_started', $hrEvents->all());
    }

    // ---- #52 policy acknowledgement needs a view ------------------------------------------------------

    public function test_policy_can_be_acknowledged_only_after_it_was_opened(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/company/policy.pdf', '%PDF-1.4 test');
        $user = $this->user('employee');
        $this->employee($user);
        $doc = new CompanyDocument(['category' => 'policy', 'title' => 'Code of conduct', 'audience' => 'all', 'requires_acknowledgement' => true,
            'file_path' => 'documents/company/policy.pdf', 'file_name' => 'policy.pdf', 'mime_type' => 'application/pdf', 'file_size' => 13]);
        $doc->company_id = $this->company->id;
        $doc->version = 1;
        $doc->save();

        $this->actingAs($user)->postJson("/api/documents/company/{$doc->id}/acknowledge")
            ->assertStatus(422)->assertJsonFragment(['message' => 'Open the document and read it before acknowledging it.']);
        $row = collect($this->actingAs($user)->getJson('/api/documents/company')->assertOk()->json('data'))->firstWhere('id', $doc->id);
        $this->assertFalse($row['viewed']);

        $this->actingAs($user)->get("/api/documents/company/{$doc->id}/download?inline=1")->assertOk();
        $this->assertDatabaseHas('company_document_views', ['company_document_id' => $doc->id, 'version' => 1]);
        $row = collect($this->actingAs($user)->getJson('/api/documents/company')->json('data'))->firstWhere('id', $doc->id);
        $this->assertTrue($row['viewed']);

        $this->actingAs($user)->postJson("/api/documents/company/{$doc->id}/acknowledge")->assertOk();
        $this->assertDatabaseHas('company_document_acknowledgements', ['company_document_id' => $doc->id]);

        // A new version must be opened again before it can be acknowledged.
        $doc->forceFill(['version' => 2])->save();
        $doc->acknowledgements()->delete();
        $this->actingAs($user)->postJson("/api/documents/company/{$doc->id}/acknowledge")->assertStatus(422);
    }

    // ---- #56 positions -------------------------------------------------------------------------------

    public function test_position_needs_a_level_and_the_summary_counts_it(): void
    {
        $hr = $this->user('hr-admin');
        $this->actingAs($hr)->postJson('/api/organization/positions', ['title' => 'Clerk'])
            ->assertStatus(422)->assertJsonValidationErrors('employment_level_id');

        $level = $this->actingAs($hr)->postJson('/api/organization/levels', ['name' => 'Junior', 'probation_months' => 3, 'probation_review_days' => 14])
            ->assertSuccessful()->json('data.id');
        $before = $this->actingAs($hr)->getJson('/api/organization/summary')->json('positions');
        $this->actingAs($hr)->postJson('/api/organization/positions', ['title' => 'Clerk', 'employment_level_id' => $level])->assertSuccessful();
        $this->assertSame($before + 1, $this->actingAs($hr)->getJson('/api/organization/summary')->json('positions'));
    }
}
