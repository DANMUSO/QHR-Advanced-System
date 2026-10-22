<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginActivity;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Jenssegers\Agent\Agent;

/** Self-service account security: active sessions and sign-in history. */
class SecurityController extends Controller
{
    public function sessions(Request $request): JsonResponse
    {
        $current = $request->session()->getId();

        $sessions = DB::table('sessions')->where('user_id', $request->user()->id)
            ->orderByDesc('last_activity')->get()
            ->map(function ($s) use ($current) {
                $agent = new Agent;
                $agent->setUserAgent($s->user_agent ?? '');

                return [
                    'id' => $s->id,
                    'ip_address' => $s->ip_address,
                    'browser' => $agent->browser() ?: 'Unknown',
                    'platform' => $agent->platform() ?: 'Unknown',
                    'device' => $agent->isDesktop() ? 'Desktop' : ($agent->isMobile() ? 'Mobile' : 'Unknown'),
                    'last_active' => Carbon::createFromTimestamp($s->last_activity)->toIso8601String(),
                    'is_current' => $s->id === $current,
                ];
            });

        return response()->json(['data' => $sessions]);
    }

    public function destroySession(Request $request, string $id): JsonResponse
    {
        abort_if($id === $request->session()->getId(), 422, 'Use sign out to end your current session.');
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', $id)->delete();

        return response()->json(['message' => 'Session signed out.']);
    }

    public function destroyOtherSessions(Request $request): JsonResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $count = DB::table('sessions')->where('user_id', $request->user()->id)
            ->where('id', '!=', $request->session()->getId())->delete();

        activity('security')->causedBy($request->user())->log("Signed out {$count} other session(s)");

        return response()->json(['message' => "Signed out {$count} other session(s)."]);
    }

    public function loginHistory(Request $request): JsonResponse
    {
        return response()->json(
            LoginActivity::where('user_id', $request->user()->id)->latest('created_at')->paginate(15)
        );
    }
}
