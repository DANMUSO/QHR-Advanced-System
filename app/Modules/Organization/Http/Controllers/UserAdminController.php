<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ActivateAccount;
use App\Support\ModuleRegistry;
use App\Support\PrivilegedAccess;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** User account administration: roles, direct permissions, account state and support actions. */
class UserAdminController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedUsers($request)
            ->with(['roles:id,name', 'permissions:id,name', 'company:id,name', 'employee:id,user_id,first_name,last_name,employee_number,photo_path'])
            ->when(trim((string) $request->query('search')), fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")))
            ->when($request->query('role'), fn ($q, $r) => $q->whereHas('roles', fn ($w) => $w->where('name', $r)))
            ->when($request->query('status'), fn ($q, $s) => $this->whereStatus($q, $s))
            ->when(Tenancy::isGlobal() && $request->integer('company_id'), fn ($q) => $q->where('company_id', $request->integer('company_id')));

        $sort = in_array($request->query('sort'), ['name', 'email', 'last_login_at', 'created_at'], true) ? $request->query('sort') : 'name';
        $query->orderBy($sort, $request->query('dir') === 'desc' ? 'desc' : 'asc')->orderBy('id');

        $actor = $request->user();
        $page = $query->paginate(min(100, max(5, $request->integer('per_page', 20))))
            ->through(fn (User $u) => $this->row($u) + ['can_send_activation' => PrivilegedAccess::canHandOverAccess($actor, $u)]);

        $base = $this->scopedUsers($request);
        $stats = [
            'total' => (clone $base)->count(),
            'active' => $this->whereStatus(clone $base, 'active')->count(),
            'disabled' => $this->whereStatus(clone $base, 'disabled')->count(),
            'locked' => $this->whereStatus(clone $base, 'locked')->count(),
            'not_activated' => $this->whereStatus(clone $base, 'not_activated')->count(),
            'two_factor' => (clone $base)->whereNotNull('two_factor_confirmed_at')->count(),
        ];

        return response()->json(array_merge($page->toArray(), [
            'stats' => $stats,
            'roles' => Role::orderBy('name')->pluck('name'),
            'can_copy_activation_link' => app()->environment('local'),
            'can_create_users' => PrivilegedAccess::isSuper($request->user()),
        ]));
    }

    /**
     * Super admin: a login with no employee record, e.g. an external accountant or a director who only
     * approves. Emails an activation link; in local development the link can also be copied afterwards.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless(PrivilegedAccess::isSuper($actor), 403, 'Only a super admin can create a user without an employee record.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
            'send_activation' => ['boolean'],
        ], ['email.unique' => 'Another login already uses this email address.', 'roles.required' => 'Choose at least one role.']);

        $user = User::create([
            'company_id' => $data['company_id'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'is_active' => true,
        ]);
        $user->syncRoles($data['roles']);

        activity('security')->causedBy($actor)->performedOn($user)->event('created')
            ->withProperties(['attributes' => ['roles' => $data['roles'], 'company_id' => $user->company_id]])
            ->log("Standalone user {$user->email} created with roles: ".implode(', ', $data['roles']));

        $sent = $request->boolean('send_activation', true);
        if ($sent) {
            $user->notify(new ActivateAccount(Password::broker('activations')->createToken($user)));
        }
        $user->load(['roles:id,name', 'company:id,name']);

        return response()->json([
            'message' => $sent ? "User created. An activation email was sent to {$user->email}." : 'User created.',
            'data' => $this->row($user),
        ], 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $actor = $request->user();
        $user->load(['roles.permissions:id,name', 'permissions:id,name', 'company:id,name', 'employee:id,user_id,first_name,last_name,employee_number,photo_path']);

        // permission => [role names granting it]
        $fromRoles = [];
        foreach ($user->roles as $role) {
            $perms = $role->name === 'super-admin' ? Permission::pluck('name') : $role->permissions->pluck('name');
            foreach ($perms as $p) {
                $fromRoles[$p][] = $role->name;
            }
        }

        return response()->json([
            'data' => $this->row($user) + [
                'direct_permissions' => $user->permissions->pluck('name')->values(),
                'role_permissions' => (object) $fromRoles,
                'failed_login_attempts' => $user->failed_login_attempts,
                'locked_until' => $user->locked_until,
                'activated_at' => $user->activated_at,
                'password_changed_at' => $user->password_changed_at,
                'is_self' => $user->is($actor),
            ],
            'catalogue' => ModuleRegistry::permissions(),
            // Every role is listed; the ones this administrator may not grant or remove come back locked with the reason.
            'assignable_roles' => Role::orderBy('name')->get(['id', 'name'])
                ->filter(fn ($r) => $r->name !== 'super-admin' || $actor->hasRole('super-admin'))
                ->map(fn ($r) => [
                    'name' => $r->name,
                    'label' => config("hr.roles.{$r->name}.label", str($r->name)->headline()->toString()),
                    'locked' => ! PrivilegedAccess::canAssignRole($actor, $r->name),
                    'locked_reason' => PrivilegedAccess::canAssignRole($actor, $r->name) ? null
                        : 'Finance, payroll and administrator roles can only be granted or removed by a super admin (segregation of duties).',
                ])->values(),
            'grantable_permissions' => $this->grantable($actor),
            'login_history' => $user->loginActivities()->latest('created_at')->limit(10)->get(),
            'is_privileged' => $privileged = PrivilegedAccess::isPrivilegedUser($user),
            'can_copy_activation_link' => app()->environment('local') && PrivilegedAccess::canHandOverAccess($actor, $user),
            'can_resend_activation' => PrivilegedAccess::canHandOverAccess($actor, $user),
            'activation_locked_reason' => PrivilegedAccess::canHandOverAccess($actor, $user) ? null
                : 'Only a super admin can send or copy activation links for finance, payroll or administrator accounts.',
            'can_impersonate' => $actor->can('users.impersonate') && ! $user->is($actor) && $user->is_active
                && PrivilegedAccess::impersonationDenial($actor, $user) === null,
            'impersonation_locked_reason' => $actor->can('users.impersonate') && ! $user->is($actor)
                ? PrivilegedAccess::impersonationDenial($actor, $user) : null,
            'privileged_hint' => $privileged && ! PrivilegedAccess::isSuper($actor),
        ]);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $actor = $request->user();
        abort_if($user->is($actor), 422, 'You cannot change your own roles. Ask another administrator.');

        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['string', 'distinct', 'exists:roles,name'],
        ]);

        $old = $user->getRoleNames()->all();
        $new = array_values($data['roles']);
        $touchesSuper = in_array('super-admin', $new, true) || in_array('super-admin', $old, true);
        abort_if($touchesSuper && ! $actor->hasRole('super-admin'), 403, 'Only a super admin can grant or remove the super admin role.');
        // Segregation of duties: HR may move staff between employee and supervisor only.
        $changed = array_merge(array_diff($new, $old), array_diff($old, $new));
        $locked = array_values(array_filter($changed, fn ($r) => ! PrivilegedAccess::canAssignRole($actor, $r)));
        abort_if($locked, 403, 'Only a super admin can grant or remove these roles: '.implode(', ', $locked).'.');

        $user->syncRoles($new);

        if (array_diff($old, $new) || array_diff($new, $old)) {
            activity('security')->causedBy($actor)->performedOn($user)->event('updated')
                ->withProperties(['old' => ['roles' => $old], 'attributes' => ['roles' => $new]])
                ->log("Roles changed for {$user->name}: ".($new ? implode(', ', $new) : 'none'));
        }

        return response()->json(['message' => 'Roles updated.', 'roles' => $user->getRoleNames()]);
    }

    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $actor = $request->user();

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
        ]);

        $grantable = $this->grantable($actor);
        $current = $user->getDirectPermissions()->pluck('name')->all();
        $denied = array_diff(array_merge(array_diff($data['permissions'], $current), array_diff($current, $data['permissions'])), $grantable);
        abort_if($denied, 403, 'You cannot grant or revoke: '.implode(', ', $denied).'.');

        $user->syncPermissions($data['permissions']);

        $added = array_values(array_diff($data['permissions'], $current));
        $removed = array_values(array_diff($current, $data['permissions']));
        if ($added || $removed) {
            activity('security')->causedBy($actor)->performedOn($user)->event('updated')
                ->withProperties(['old' => ['permissions' => $current], 'attributes' => ['permissions' => array_values($data['permissions'])], 'granted' => $added, 'revoked' => $removed])
                ->log("Direct permissions changed for {$user->name}".($added ? '; granted '.implode(', ', $added) : '').($removed ? '; revoked '.implode(', ', $removed) : ''));
        }

        return response()->json(['message' => 'Permissions updated.', 'direct_permissions' => $user->getDirectPermissions()->pluck('name')]);
    }

    public function disable(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        abort_if($user->is($request->user()), 422, 'You cannot disable your own account.');

        $user->forceFill(['is_active' => false])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();

        return $this->done($request, $user, "Account disabled for {$user->name}", 'Account disabled. The user has been signed out.');
    }

    public function enable(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $user->forceFill(['is_active' => true])->save();

        return $this->done($request, $user, "Account enabled for {$user->name}", 'Account enabled.');
    }

    public function unlock(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $user->clearFailedLogins();

        return $this->done($request, $user, "Account unlocked for {$user->name}", 'Account unlocked.');
    }

    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        abort_unless($user->two_factor_secret || $user->two_factor_confirmed_at, 422, 'Two-factor authentication is not enabled for this user.');

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        return $this->done($request, $user, "Two-factor authentication reset for {$user->name}", 'Two-factor authentication reset. The user will be asked to set it up again if their role requires it.');
    }

    public function resendActivation(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        abort_unless(PrivilegedAccess::canHandOverAccess($request->user(), $user), 403, 'Only a super admin can send activation emails for finance, payroll or administrator accounts.');
        abort_if($user->activated_at !== null, 422, 'This account is already activated.');
        abort_unless($user->is_active, 422, 'Enable the account before sending an activation email.');

        $user->notify(new ActivateAccount(Password::broker('activations')->createToken($user)));

        return $this->done($request, $user, "Activation email resent to {$user->email}", "Activation email sent to {$user->email}.");
    }

    /**
     * Local development only: returns a fresh activation link so testers can activate accounts without a
     * mailbox. Issuing it replaces any link emailed earlier.
     */
    public function activationLink(Request $request, User $user): JsonResponse
    {
        abort_unless(app()->environment('local'), 403, 'Activation links can only be copied in local development.');
        $this->authorizeTarget($request, $user);
        abort_unless(PrivilegedAccess::canHandOverAccess($request->user(), $user), 403, 'Only a super admin can copy activation links for finance, payroll or administrator accounts.');
        abort_if($user->activated_at !== null, 422, 'This account is already activated.');
        abort_unless($user->is_active, 422, 'Enable the account before activating it.');

        $token = Password::broker('activations')->createToken($user);
        activity('security')->causedBy($request->user())->performedOn($user)->log("Activation link copied for {$user->email} (local)");

        return response()->json([
            'message' => 'Activation link created. It replaces any link emailed earlier.',
            'url' => url('/activate?'.http_build_query(['token' => $token, 'email' => $user->email])),
        ]);
    }

    public function forcePasswordChange(Request $request, User $user): JsonResponse
    {
        $this->authorizeTarget($request, $user);
        $value = $request->has('value') ? $request->boolean('value') : true;
        $user->forceFill(['must_change_password' => $value])->save();

        return $value
            ? $this->done($request, $user, "Password change forced for {$user->name}", 'The user must change their password at next sign-in.')
            : $this->done($request, $user, "Forced password change cleared for {$user->name}", 'Forced password change cleared.');
    }

    // ----------------------------------------------------------------------------------------

    private function scopedUsers(Request $request): Builder
    {
        $query = User::query();
        if (! Tenancy::isGlobal()) {
            return $query->where('company_id', $request->user()->company_id);
        }

        return $query->when(Tenancy::companyId(), fn ($q, $id) => $q->where('company_id', $id));
    }

    private function whereStatus(Builder $q, string $status): Builder
    {
        return match ($status) {
            'disabled' => $q->where('is_active', false),
            'locked' => $q->where('is_active', true)->where('locked_until', '>', now()),
            'not_activated' => $q->where('is_active', true)->whereNull('activated_at')
                ->where(fn ($w) => $w->whereNull('locked_until')->orWhere('locked_until', '<=', now())),
            'active' => $q->where('is_active', true)->whereNotNull('activated_at')
                ->where(fn ($w) => $w->whereNull('locked_until')->orWhere('locked_until', '<=', now())),
            default => $q,
        };
    }

    private function status(User $u): string
    {
        return match (true) {
            ! $u->is_active => 'disabled',
            $u->isLocked() => 'locked',
            $u->activated_at === null => 'not_activated',
            default => 'active',
        };
    }

    private function row(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'initials' => $u->initials(),
            'company' => $u->company?->only(['id', 'name']),
            'roles' => $u->roles->pluck('name')->values(),
            'two_factor_enabled' => $u->hasTwoFactorEnabled(),
            'last_login_at' => $u->last_login_at,
            'last_login_ip' => $u->last_login_ip,
            'status' => $this->status($u),
            'must_change_password' => (bool) $u->must_change_password,
            'employee' => $u->employee ? [
                'id' => $u->employee->id,
                'name' => $u->employee->full_name,
                'employee_number' => $u->employee->employee_number,
                'photo_url' => $u->employee->photo_url,
            ] : null,
            'created_at' => $u->created_at,
        ];
    }

    /** @return list<string> */
    private function grantable(User $actor): array
    {
        $all = Permission::orderBy('name')->pluck('name');
        if ($actor->hasRole('super-admin')) {
            return $all->values()->all();
        }
        $own = $actor->getAllPermissions()->pluck('name')->all();

        return $all->filter(fn ($p) => in_array($p, $own, true) && ! PrivilegedAccess::isPrivilegedPermission($p))->values()->all();
    }

    private function authorizeTarget(Request $request, User $user): void
    {
        $actor = $request->user();
        if ($actor->hasRole('super-admin')) {
            return;
        }
        abort_unless($user->company_id !== null && $user->company_id === $actor->company_id, 404);
        abort_if($user->hasRole('super-admin'), 403, 'Only a super admin can manage a super admin account.');
    }

    private function done(Request $request, User $user, string $log, string $message): JsonResponse
    {
        activity('security')->causedBy($request->user())->performedOn($user)->log($log);
        $user->refresh()->load(['roles:id,name', 'company:id,name']);

        return response()->json(['message' => $message, 'data' => $this->row($user)]);
    }
}
