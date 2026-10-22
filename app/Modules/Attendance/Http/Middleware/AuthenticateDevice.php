<?php

namespace App\Modules\Attendance\Http\Middleware;

use App\Modules\Attendance\Models\AttendanceDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Authenticates biometric terminals with "Authorization: Bearer <device token>". */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $device = $token ? AttendanceDevice::withoutGlobalScope('company')
            ->where('token_hash', AttendanceDevice::hashToken($token))->first() : null;

        if (! $device || ! $device->active) {
            return response()->json(['message' => 'Invalid or revoked device token.'], 401);
        }

        // Cheap heartbeat on every call (at most once a minute per device).
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute()) || $device->last_ip !== $request->ip()) {
            $device->forceFill(['last_seen_at' => now(), 'last_ip' => $request->ip()])->saveQuietly();
        }

        $request->attributes->set('attendance_device', $device);

        return $next($request);
    }
}
