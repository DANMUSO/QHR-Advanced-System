<?php

namespace Tests\Feature\Organization;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 2: company/branch/department phone and KRA PIN validation; local-only activation links. */
class OrganizationQa2Test extends TestCase
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

    private function user(string $role, bool $activated = true): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => "User {$role}", 'email' => uniqid($role).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => $activated ? now() : null,
        ]);
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_company_create_and_update_validate_phone_and_kra_pin(): void
    {
        $super = $this->user('super-admin');

        $this->actingAs($super)->postJson('/api/organization/companies', ['name' => 'Bad Co', 'code' => 'BAD', 'phone' => 'abc123', 'tax_pin' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone', 'tax_pin']);

        $this->actingAs($super)->postJson('/api/organization/companies', ['name' => 'Good Co', 'code' => 'GOOD', 'phone' => '+254712345678', 'tax_pin' => 'P051234567X'])
            ->assertCreated();

        $hr = $this->user('hr-admin');
        $this->actingAs($hr)->putJson("/api/organization/companies/{$this->company->id}", ['name' => 'Acme Ltd', 'phone' => 'abc123', 'tax_pin' => '12345'])
            ->assertStatus(422)->assertJsonValidationErrors(['phone', 'tax_pin']);
        $this->actingAs($hr)->putJson("/api/organization/companies/{$this->company->id}", ['name' => 'Acme Ltd', 'phone' => '0712345678', 'tax_pin' => 'A123456789Z'])
            ->assertOk();
    }

    public function test_branch_and_department_phone_is_validated(): void
    {
        $hr = $this->user('hr-admin');

        foreach (['branches', 'departments'] as $type) {
            $this->actingAs($hr)->postJson("/api/organization/{$type}", ['name' => "Bad {$type}", 'phone' => 'abc123'])
                ->assertStatus(422)->assertJsonValidationErrors(['phone']);
            $id = $this->actingAs($hr)->postJson("/api/organization/{$type}", ['name' => "Good {$type}", 'phone' => '0712345678'])
                ->assertSuccessful()->json('data.id');
            $this->actingAs($hr)->putJson("/api/organization/{$type}/{$id}", ['name' => "Good {$type}", 'phone' => 'call me'])
                ->assertStatus(422)->assertJsonValidationErrors(['phone']);
        }
    }

    public function test_activation_link_is_forbidden_outside_local(): void
    {
        $admin = $this->user('hr-admin');
        $pending = $this->user('employee', activated: false);

        $this->assertFalse(app()->environment('local'));
        $this->actingAs($admin)->postJson("/api/admin/users/{$pending->id}/activation-link")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->assertJson(['can_copy_activation_link' => false]);
    }

    public function test_activation_link_works_in_local_for_admins_only(): void
    {
        $this->app['env'] = 'local';
        $admin = $this->user('hr-admin');
        $pending = $this->user('employee', activated: false);

        $url = $this->actingAs($admin)->postJson("/api/admin/users/{$pending->id}/activation-link")->assertOk()->json('url');
        $this->assertStringContainsString('/activate?token=', $url);
        $this->assertStringContainsString(urlencode($pending->email), $url);

        // The link actually activates the account.
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->postJson('/api/auth/activate', ['token' => $q['token'], 'email' => $q['email'], 'password' => 'N3w-Passw0rd!x', 'password_confirmation' => 'N3w-Passw0rd!x'])
            ->assertOk();
        $this->assertNotNull($pending->fresh()->activated_at);

        // Already activated, and non-admins are refused.
        $this->actingAs($admin)->postJson("/api/admin/users/{$pending->id}/activation-link")->assertStatus(422);
        $other = $this->user('employee', activated: false);
        $this->actingAs($this->user('employee'))->postJson("/api/admin/users/{$other->id}/activation-link")->assertForbidden();
    }
}
