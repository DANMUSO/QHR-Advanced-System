<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordController extends Controller
{
    /** Always answers the same way so the endpoint cannot be used to discover which emails exist. */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker('users')->sendResetLink(
            $request->only('email'),
            function (User $user, string $token) {
                if ($user->is_active && $user->password) {
                    $user->notify(new ResetPasswordLink($token));
                }
            }
        );

        return response()->json(['message' => 'If an account exists for that email, a reset link has been sent.']);
    }

    public function reset(Request $request): JsonResponse
    {
        return $this->completeWithBroker($request, 'users', 'Your password has been reset. You can now sign in.');
    }

    public function activate(Request $request): JsonResponse
    {
        return $this->completeWithBroker($request, 'activations', 'Your account is active. You can now sign in.', activating: true);
    }

    public function change(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::defaults()],
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $request->input('password'),
            'password_changed_at' => now(),
            'must_change_password' => false,
        ])->save();

        // Sign out every other device for this account.
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();

        activity('security')->causedBy($user)->performedOn($user)->log('Changed password');

        return response()->json(['message' => 'Password updated. Other devices have been signed out.']);
    }

    private function completeWithBroker(Request $request, string $broker, string $successMessage, bool $activating = false): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::broker($broker)->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use ($activating) {
                $user->forceFill([
                    'password' => $password,
                    'password_changed_at' => now(),
                    'must_change_password' => false,
                    'failed_login_attempts' => 0,
                    'locked_until' => null,
                    'remember_token' => Str::random(60),
                ]);
                if ($activating) {
                    $user->activated_at ??= now();
                    $user->email_verified_at ??= now();
                }
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => $status === Password::INVALID_TOKEN
                    ? 'This link is invalid or has expired. Request a new one.'
                    : __($status),
            ]);
        }

        // Whoever was signed in on this browser (e.g. an admin opening someone's activation link) is signed out, so the
        // next step is a clean sign-in as the account that was just activated or reset.
        if (\Illuminate\Support\Facades\Auth::guard('web')->check()) {
            \Illuminate\Support\Facades\Auth::guard('web')->logout();
        }
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => $successMessage, 'email' => $request->input('email')]);
    }
}
