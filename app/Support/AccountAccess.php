<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Account-level access rules shared by sign-in and the account.secure middleware:
 * deactivated companies and server-side idle / absolute session timeouts (spec §13).
 */
class AccountAccess
{
    public const INACTIVE_COMPANY_MESSAGE = 'Your organisation\'s account has been deactivated. Contact your system administrator.';

    /** Absolute session lifetime regardless of activity. */
    public const ABSOLUTE_TIMEOUT_MINUTES = 240;

    /** Requests sent with this header (background polling) do not count as user activity. */
    public const BACKGROUND_HEADER = 'X-Background-Request';

    /** True when the user's company is deactivated. Super admins are never blocked. */
    public static function companyInactive(User $user): bool
    {
        if (! $user->company_id || $user->hasRole('super-admin')) {
            return false;
        }

        return $user->company !== null && ! $user->company->is_active;
    }

    public static function idleTimeoutMinutes(User $user): int
    {
        return max(5, (int) ($user->company?->setting('idle_timeout_minutes') ?? 30));
    }

    /** Stamps a fresh session at sign-in. */
    public static function startSession(Request $request): void
    {
        if ($request->hasSession()) {
            $request->session()->put(['auth.started_at' => time(), 'auth.last_activity_at' => time()]);
        }
    }

    /**
     * Returns 'session_idle' / 'session_expired' when the session has timed out, otherwise records
     * the activity (unless it is a background request) and returns null.
     */
    public static function checkSession(Request $request, User $user): ?string
    {
        if (! $request->hasSession() || ! $request->session()->isStarted()) {
            return null; // API token requests have no session
        }
        $session = $request->session();
        $now = time();
        $started = (int) $session->get('auth.started_at', 0);
        $last = (int) $session->get('auth.last_activity_at', 0);

        if (! $started) {
            // Session from before this rule (or re-established by a remember-me cookie).
            $session->put(['auth.started_at' => $now, 'auth.last_activity_at' => $now]);

            return null;
        }
        if ($now - $started > self::ABSOLUTE_TIMEOUT_MINUTES * 60) {
            return 'session_expired';
        }
        if ($last && $now - $last > self::idleTimeoutMinutes($user) * 60) {
            return 'session_idle';
        }
        if (! $request->headers->has(self::BACKGROUND_HEADER)) {
            $session->put('auth.last_activity_at', $now);
        }

        return null;
    }
}
