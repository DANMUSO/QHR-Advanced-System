<?php

namespace App\Http\Middleware;

use App\Support\AccountAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the API (except the account-security endpoints themselves) until the user has
 * changed a temporary password and, where their role requires it, set up two-factor authentication.
 * Also signs out users of a deactivated company and sessions past the idle or absolute timeout.
 */
class EnsureAccountIsSecure
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if (! $user->is_active) {
            return $this->signOut($request, 'Your account has been disabled.', 'account_disabled');
        }

        $impersonating = $request->hasSession() && $request->session()->has('impersonator_id');

        if (! $impersonating && AccountAccess::companyInactive($user)) {
            return $this->signOut($request, AccountAccess::INACTIVE_COMPANY_MESSAGE, 'company_inactive');
        }

        if ($timeout = AccountAccess::checkSession($request, $user)) {
            return $this->signOut($request, $timeout === 'session_idle'
                ? 'You were signed out after a period of inactivity. Please sign in again.'
                : 'Your session has reached its maximum length. Please sign in again.', $timeout);
        }

        if ($impersonating) {
            return $next($request);
        }

        if ($user->must_change_password) {
            return response()->json(['message' => 'You must change your password before continuing.', 'code' => 'password_change_required'], 423);
        }

        if ($user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Your role requires two-factor authentication. Set it up to continue.', 'code' => 'two_factor_setup_required'], 423);
        }

        return $next($request);
    }

    private function signOut(Request $request, string $message, string $code): Response
    {
        if ($request->hasSession() && $request->session()->isStarted()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
        }

        return response()->json(['message' => $message, 'code' => $code], 401);
    }
}
