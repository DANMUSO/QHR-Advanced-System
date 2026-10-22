<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use PragmaRX\Google2FA\Google2FA;

class TwoFactor
{
    public function __construct(private Google2FA $engine = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        return $writer->writeString($url);
    }

    public function verify(string $secret, string $code): bool
    {
        return (bool) $this->engine->verifyKey($secret, preg_replace('/\s+/', '', $code), 1);
    }

    /** Validates either a TOTP code or a single-use recovery code (which is then consumed). */
    public function verifyForUser(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code && $this->verify($user->two_factor_secret, $code)) {
            return true;
        }
        if ($recoveryCode) {
            $codes = collect($user->two_factor_recovery_codes ?? []);
            $match = $codes->first(fn ($c) => hash_equals($c, strtoupper(trim($recoveryCode))));
            if ($match) {
                $user->forceFill(['two_factor_recovery_codes' => $codes->reject(fn ($c) => $c === $match)->values()->all()])->save();

                return true;
            }
        }

        return false;
    }
}
