<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Services\Auth\LoginRecorder;
use App\Services\Auth\TwoFactor;
use App\Support\AccountAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private LoginRecorder $recorder) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $key = 'login|'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ])->status(429);
        }

        $user = User::where('email', $data['email'])->first();

        if ($user?->isLocked()) {
            $this->recorder->record('locked', $user);
            throw ValidationException::withMessages([
                'email' => 'This account is temporarily locked after repeated failed sign-ins. Try again in '
                    .max(1, (int) ceil(now()->diffInMinutes($user->locked_until))).' minute(s) or contact HR.',
            ])->status(423);
        }

        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            $this->recorder->record('failed', $user, $data['email']);
            $message = 'These credentials do not match our records.';
            if ($user && $user->password) {
                $user->registerFailedLogin();
                $remaining = User::MAX_FAILED_ATTEMPTS - $user->failed_login_attempts;
                $message = $user->isLocked()
                    ? 'Too many failed attempts. Your account is locked for '.User::LOCKOUT_MINUTES.' minutes.'
                    : ($remaining <= 2 ? $message." {$remaining} attempt(s) left before your account is locked." : $message);
            } elseif ($user && ! $user->password) {
                $message = 'Your account has not been activated yet. Use the activation link sent to your email.';
            }
            throw ValidationException::withMessages(['email' => $message]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Your account has been disabled. Contact HR.'])->status(403);
        }

        if (AccountAccess::companyInactive($user)) {
            $this->recorder->record('failed', $user, $data['email']);
            throw ValidationException::withMessages(['email' => AccountAccess::INACTIVE_COMPANY_MESSAGE])->status(403);
        }

        RateLimiter::clear($key);
        $user->clearFailedLogins();

        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', (bool) ($data['remember'] ?? false));

            return response()->json(['two_factor' => true]);
        }

        return $this->completeLogin($request, $user, (bool) ($data['remember'] ?? false));
    }

    public function twoFactorChallenge(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $request->validate(['code' => ['nullable', 'string'], 'recovery_code' => ['nullable', 'string']]);

        $user = User::find($request->session()->get('login.id'));
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'Your sign-in session expired. Please sign in again.'])->status(419);
        }

        $key = 'two-factor|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.'])->status(429);
        }

        if (! $twoFactor->verifyForUser($user, $request->input('code'), $request->input('recovery_code'))) {
            RateLimiter::hit($key, 60);
            $this->recorder->record('two_factor_failed', $user);
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget('login.id');

        return $this->completeLogin($request, $user, $remember);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($user = $request->user()) {
            $this->recorder->record('logout', $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): MeResource|JsonResponse
    {
        $user = $request->user();
        $impersonating = $request->hasSession() && $request->session()->has('impersonator_id');
        $code = ! $impersonating && AccountAccess::companyInactive($user) ? 'company_inactive' : AccountAccess::checkSession($request, $user);
        if ($code) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }

            return response()->json([
                'message' => $code === 'company_inactive' ? AccountAccess::INACTIVE_COMPANY_MESSAGE : 'Your session has expired. Please sign in again.',
                'code' => $code,
            ], 401);
        }

        return new MeResource($user);
    }

    private function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        AccountAccess::startSession($request);

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        $this->recorder->record('login', $user);

        return response()->json(['user' => new MeResource($user->fresh())]);
    }
}
