<?php

namespace App\Support;

use App\Models\User;

/**
 * Resolves which company the current request operates on.
 * Super admins may act across all companies, optionally narrowing with the X-Company-Id header;
 * every other user is pinned to their own company.
 */
class Tenancy
{
    public static function user(): ?User
    {
        return auth()->user();
    }

    public static function isGlobal(): bool
    {
        $user = static::user();

        return $user !== null && $user->hasRole('super-admin');
    }

    /** Company id to scope queries by, or null when a super admin is viewing all companies. */
    public static function companyId(): ?int
    {
        $user = static::user();
        if (! $user) {
            return null;
        }
        if (static::isGlobal()) {
            $header = request()?->header('X-Company-Id');

            return $header ? (int) $header : null;
        }

        return $user->company_id;
    }

    /** Company id to stamp on newly created records. */
    public static function companyIdForWrite(?int $requested = null): ?int
    {
        if (static::isGlobal()) {
            return $requested ?: static::companyId();
        }

        return static::user()?->company_id;
    }
}
