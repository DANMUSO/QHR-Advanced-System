<?php

namespace App\Support;

use App\Models\User;

/**
 * Segregation of duties for account administration. HR (users.manage) may hand out only the staff roles;
 * every other role (finance, payroll officer, HR admin, super admin, or any custom approver role) and every
 * money-moving or admin permission is granted, revoked, activated or impersonated by a super admin only.
 */
class PrivilegedAccess
{
    /** Roles a non-super administrator may grant or remove. */
    public const STAFF_ROLES = ['employee', 'supervisor'];

    /** Permissions that only a super admin may grant directly (payroll.* except view-own is matched below). */
    public const SUPER_ONLY_PERMISSIONS = ['companies.manage', 'claims.pay', 'users.manage', 'users.impersonate'];

    public static function isSuper(?User $user): bool
    {
        return $user !== null && $user->hasRole('super-admin');
    }

    public static function isPrivilegedRole(string $role): bool
    {
        return ! in_array($role, self::STAFF_ROLES, true);
    }

    public static function isPrivilegedPermission(string $permission): bool
    {
        return in_array($permission, self::SUPER_ONLY_PERMISSIONS, true)
            || (str_starts_with($permission, 'payroll.') && $permission !== 'payroll.view-own');
    }

    /** True when the account holds any role beyond employee/supervisor, or a privileged direct permission. */
    public static function isPrivilegedUser(User $user): bool
    {
        return $user->getRoleNames()->contains(fn ($r) => self::isPrivilegedRole($r))
            || $user->getDirectPermissions()->pluck('name')->contains(fn ($p) => self::isPrivilegedPermission($p));
    }

    /** Whether $actor may grant or remove $role on someone else's account. */
    public static function canAssignRole(User $actor, string $role): bool
    {
        return self::isSuper($actor) || ! self::isPrivilegedRole($role);
    }

    /** Whether $actor may run account actions that hand over access (activation links) for $target. */
    public static function canHandOverAccess(User $actor, User $target): bool
    {
        return self::isSuper($actor) || ! self::isPrivilegedUser($target);
    }

    /**
     * Reason $actor may not sign in as $target, or null when allowed. Outside super admins, impersonation
     * cannot reach privileged accounts or any account holding a permission the impersonator lacks.
     */
    public static function impersonationDenial(User $actor, User $target): ?string
    {
        if (self::isSuper($actor)) {
            return null;
        }
        if (self::isPrivilegedUser($target)) {
            return "Only a super admin can sign in as {$target->name}: the account holds a privileged role (finance, payroll or administration).";
        }
        $mine = $actor->getAllPermissions()->pluck('name')->all();
        $extra = $target->getAllPermissions()->pluck('name')->diff($mine)->values();
        if ($extra->isNotEmpty()) {
            return "You cannot sign in as {$target->name}: the account has permissions you do not hold ({$extra->take(3)->implode(', ')}).";
        }

        return null;
    }
}
