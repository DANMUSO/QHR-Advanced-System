<?php

namespace App\Modules\Organization\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ModuleRegistry;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Role templates with their permissions. Read-only for HR; super admins may edit a role's permissions. */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companyId = Tenancy::isGlobal() ? Tenancy::companyId() : $request->user()->company_id;
        $counts = DB::table('model_has_roles')
            ->where('model_type', (new User)->getMorphClass())
            ->when($companyId, fn ($q) => $q->whereIn('model_id', User::where('company_id', $companyId)->select('id')))
            ->select('role_id', DB::raw('count(*) as c'))->groupBy('role_id')->pluck('c', 'role_id');

        $allPermissions = Permission::orderBy('name')->pluck('name');
        $order = array_keys(config('hr.roles', []));

        $roles = Role::with('permissions:id,name')->get()
            ->sortBy(fn ($r) => ($i = array_search($r->name, $order, true)) === false ? 999 : $i)
            ->map(fn (Role $r) => [
                'id' => $r->id,
                'name' => $r->name,
                'label' => config("hr.roles.{$r->name}.label", str($r->name)->headline()->toString()),
                'is_super' => $r->name === 'super-admin',
                'permissions' => $r->name === 'super-admin' ? $allPermissions : $r->permissions->pluck('name')->sort()->values(),
                'users_count' => (int) ($counts[$r->id] ?? 0),
            ])->values();

        return response()->json([
            'data' => $roles,
            'catalogue' => ModuleRegistry::permissions(),
            'can_edit' => $request->user()->hasRole('super-admin'),
        ]);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->hasRole('super-admin'), 403, 'Only a super admin can edit role permissions.');
        abort_if($role->name === 'super-admin', 422, 'The super admin role always has every permission.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', 'exists:permissions,name'],
        ]);

        $old = $role->permissions()->pluck('name')->sort()->values()->all();
        $role->syncPermissions($data['permissions']);
        $new = collect($data['permissions'])->sort()->values()->all();

        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        if ($added || $removed) {
            activity('security')->causedBy($request->user())->performedOn($role)->event('updated')
                ->withProperties(['old' => ['permissions' => $old], 'attributes' => ['permissions' => $new], 'granted' => $added, 'revoked' => $removed])
                ->log("Role \"{$role->name}\" permissions changed".($added ? '; granted '.implode(', ', $added) : '').($removed ? '; revoked '.implode(', ', $removed) : ''));
        }

        return response()->json(['message' => 'Role permissions saved.', 'permissions' => $new]);
    }
}
