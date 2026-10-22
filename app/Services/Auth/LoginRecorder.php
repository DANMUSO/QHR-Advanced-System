<?php

namespace App\Services\Auth;

use App\Models\LoginActivity;
use App\Models\User;
use App\Notifications\NewDeviceLogin;
use Illuminate\Http\Request;
use Jenssegers\Agent\Agent;

class LoginRecorder
{
    public function __construct(private Request $request) {}

    public function record(string $event, ?User $user, ?string $email = null): LoginActivity
    {
        $agent = new Agent;
        $agent->setUserAgent($ua = (string) $this->request->userAgent());

        $device = $agent->isDesktop() ? 'Desktop' : ($agent->isTablet() ? 'Tablet' : ($agent->isMobile() ? 'Mobile' : 'Unknown'));
        $browser = $agent->browser() ?: 'Unknown';
        $platform = $agent->platform() ?: 'Unknown';

        $newDevice = false;
        if ($event === 'login' && $user) {
            // A device is "known" if this user previously signed in successfully with the same browser + platform from this IP.
            $newDevice = $user->loginActivities()->where('event', 'login')->exists()
                && ! $user->loginActivities()->where('event', 'login')
                    ->where('browser', $browser)->where('platform', $platform)
                    ->where('ip_address', $this->request->ip())->exists();
        }

        $activity = LoginActivity::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'event' => $event,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr($ua, 0, 500),
            'device' => $device,
            'browser' => $browser,
            'platform' => $platform,
            'new_device' => $newDevice,
        ]);

        if ($newDevice) {
            $user->notify(new NewDeviceLogin($activity));
        }

        return $activity;
    }
}
