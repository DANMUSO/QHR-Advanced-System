<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** Starts setup: creates an unconfirmed secret and returns the QR code to scan. */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Two-factor authentication is already enabled.']);
        }

        $secret = $this->twoFactor->generateSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return response()->json([
            'secret' => $secret,
            'qr_svg' => $this->twoFactor->qrCodeSvg($user, $secret),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        if (! $user->two_factor_secret || ! $this->twoFactor->verify($user->two_factor_secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'The code is invalid. Check your authenticator app and try again.']);
        }

        $codes = User::generateRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        activity('security')->causedBy($user)->performedOn($user)->log('Enabled two-factor authentication');

        return response()->json(['recovery_codes' => $codes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();

        if ($user->requiresTwoFactor()) {
            throw ValidationException::withMessages(['current_password' => 'Your role requires two-factor authentication, so it cannot be turned off.']);
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        activity('security')->causedBy($user)->performedOn($user)->log('Disabled two-factor authentication');

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();
        abort_unless($user->hasTwoFactorEnabled(), 422, 'Two-factor authentication is not enabled.');

        $codes = User::generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return response()->json(['recovery_codes' => $codes]);
    }
}
