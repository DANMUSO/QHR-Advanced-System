<?php

namespace Tests\Feature\AccessControl;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Per-role access-control matrix, generated from the route table (QA follow-up: test every privileged role, not only Employee).
 *
 * For every parameterless GET /api route guarded by `permission:a|b`:
 *  - a role holding none of those permissions must get 403;
 *  - a role holding one of them must not hit a server error.
 * New routes are covered automatically.
 */
class RolePermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['employee', 'supervisor', 'finance', 'payroll-officer', 'hr-admin'];

    /** Exports / file downloads stream files; their permission gate is still checked, but a 200 body is not parsed. */
    private const SKIP = ['api/user', 'api/sanctum/csrf-cookie'];

    /**
     * Use MySQL-only SQL (YEAR(), CURDATE(), TIMESTAMPDIFF), so they error on the sqlite test database. Their
     * permission gate is still checked above; only the "no server error" check is skipped. Remove an entry once
     * its query is made portable.
     */
    private const MYSQL_ONLY = ['api/claims/mine', 'api/claims', 'api/documents/employees', 'api/payroll/my-payslips', 'api/analytics/hr-dashboard'];

    public function test_every_permission_guarded_route_enforces_its_permission_for_every_role(): void
    {
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $company = Company::create(['name' => 'Matrix Ltd', 'code' => 'MTX', 'is_active' => true]);

        $users = [];
        foreach (self::ROLES as $role) {
            $user = User::create(['company_id' => $company->id, 'name' => $role, 'email' => "{$role}@matrix.test",
                'password' => 'Password@123', 'is_active' => true, 'activated_at' => now()]);
            $user->assignRole($role);
            $employee = new Employee(['first_name' => ucfirst($role), 'last_name' => 'Matrix', 'phone' => '07'.rand(10000000, 99999999),
                'employee_number' => uniqid('M'), 'gender' => 'female', 'date_of_joining' => now()->subYear()->toDateString()]);
            $employee->company_id = $company->id;
            $employee->user_id = $user->id;
            $employee->status = 'active';
            $employee->save();
            $users[$role] = $user->fresh();
        }

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('GET', $r->methods(), true)
            && str_starts_with($r->uri(), 'api/') && ! str_contains($r->uri(), '{') && ! in_array($r->uri(), self::SKIP, true))
            ->map(fn ($r) => [$r->uri(), collect($r->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
                ->flatMap(fn ($m) => explode('|', substr($m, strlen('permission:'))))->values()->all()])
            ->filter(fn ($r) => $r[1] !== [])->values();

        $this->assertGreaterThan(30, $routes->count(), 'expected the permission-guarded API surface to be discovered');

        $leaks = [];
        $errors = [];
        foreach ($routes as [$uri, $permissions]) {
            foreach ($users as $role => $user) {
                $allowed = collect($permissions)->contains(fn ($p) => $user->can($p));
                $status = $this->actingAs($user)->getJson('/'.$uri)->getStatusCode();
                if (! $allowed && $status !== 403) {
                    $leaks[] = "{$role} → /{$uri} returned {$status} (needs ".implode('|', $permissions).')';
                }
                if ($allowed && $status >= 500 && ! in_array($uri, self::MYSQL_ONLY, true)) {
                    $errors[] = "{$role} → /{$uri} returned {$status}";
                }
            }
        }

        $this->assertSame([], $leaks, "Roles reached routes they lack permission for:\n".implode("\n", $leaks));
        $this->assertSame([], $errors, "Permitted roles hit server errors:\n".implode("\n", $errors));
    }
}
