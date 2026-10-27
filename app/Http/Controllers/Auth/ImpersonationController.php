<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\User;
use App\Services\Auth\LoginRecorder;
use App\Support\PrivilegedAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Lets support staff sign in as another user to troubleshoot. Every start/stop is audited and in the sign-in log. */
class ImpersonationController extends Controller
{
    public function __construct(private LoginRecorder $recorder) {}

    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();
        // Checked first: while impersonating, the signed-in user is the impersonated account (usually without
        // users.impersonate), so a permission error would hide the real reason.
        if ($request->hasSession() && ($originalId = $request->session()->get('impersonator_id'))) {
            $original = User::find($originalId);
            abort(422, "You are already signed in as {$admin->name}".($original ? " (impersonated by {$original->name})" : '')
                .'. Use "Return to my account" in the banner at the top before impersonating someone else.');
        }
        abort_unless($admin->can('users.impersonate'), 403, 'You do not have permission to sign in as other users.');
        abort_if($user->is($admin), 422, 'You cannot impersonate yourself.');
        abort_if($user->hasRole('super-admin') && ! $admin->hasRole('super-admin'), 403, 'Only a super admin can impersonate another super admin.');
        abort_if(! $admin->hasRole('super-admin') && $user->company_id !== $admin->company_id, 403);
        abort_unless($user->is_active, 422, 'This account is disabled.');
        if ($reason = PrivilegedAccess::impersonationDenial($admin, $user)) {
            abort(403, $reason);
        }

        activity('security')->causedBy($admin)->performedOn($user)->log("Started impersonating {$user->name}");
        $this->recorder->record('impersonation_started', $user, null, $admin);

        $request->session()->put('impersonator_id', $admin->id);
        Auth::guard('web')->login($user);

        return response()->json(['user' => new MeResource($user)]);
    }

    public function stop(Request $request): JsonResponse
    {
        $adminId = $request->session()->pull('impersonator_id');
        abort_unless($adminId, 422, 'You are not impersonating anyone.');

        $admin = User::findOrFail($adminId);
        activity('security')->causedBy($admin)->performedOn($request->user())->log("Stopped impersonating {$request->user()->name}");
        $this->recorder->record('impersonation_stopped', $request->user(), null, $admin);
        Auth::guard('web')->login($admin);

        return response()->json(['user' => new MeResource($admin)]);
    }
}
