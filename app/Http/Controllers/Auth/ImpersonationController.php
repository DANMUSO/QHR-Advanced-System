<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\MeResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Lets support staff sign in as another user to troubleshoot. Every start/stop is audited. */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();
        abort_unless($admin->can('users.impersonate'), 403);
        abort_if($request->session()->has('impersonator_id'), 422, 'Stop the current impersonation first.');
        abort_if($user->is($admin), 422, 'You cannot impersonate yourself.');
        abort_if($user->hasRole('super-admin') && ! $admin->hasRole('super-admin'), 403, 'Only a super admin can impersonate another super admin.');
        abort_if(! $admin->hasRole('super-admin') && $user->company_id !== $admin->company_id, 403);
        abort_unless($user->is_active, 422, 'This account is disabled.');

        activity('security')->causedBy($admin)->performedOn($user)->log("Started impersonating {$user->name}");

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
        Auth::guard('web')->login($admin);

        return response()->json(['user' => new MeResource($admin)]);
    }
}
