<?php

namespace App\Http\Resources;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class MeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $user->loadMissing(['company', 'employee.position', 'employee.department']);
        $company = $user->company;
        $impersonatorId = $request->hasSession() ? $request->session()->get('impersonator_id') : null;
        $isSuper = $user->hasRole('super-admin');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials(),
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'is_super_admin' => $isSuper,
            'preferences' => $user->preferences ?? (object) [],
            'last_login_at' => $user->last_login_at,
            'company' => $company ? [
                'id' => $company->id,
                'name' => $company->name,
                'logo_url' => $company->logo_url,
                'brand_color' => $company->brand_color,
                'currency' => $company->setting('currency'),
            ] : null,
            'companies' => $isSuper ? Company::orderBy('name')->get(['id', 'name']) : null,
            'employee' => $user->employee ? [
                'id' => $user->employee->id,
                'full_name' => $user->employee->full_name,
                'photo_url' => $user->employee->photo_url,
                'position' => $user->employee->position?->title,
                'department' => $user->employee->department?->name,
                'is_supervisor' => $user->employee->isSupervisor(),
            ] : null,
            'security' => [
                'must_change_password' => $user->must_change_password,
                'two_factor_enabled' => $user->hasTwoFactorEnabled(),
                'two_factor_required' => $user->requiresTwoFactor(),
                'needs_two_factor_setup' => $user->requiresTwoFactor() && ! $user->hasTwoFactorEnabled() && ! $impersonatorId,
                'password_changed_at' => $user->password_changed_at,
                'idle_timeout_minutes' => (int) ($company?->setting('idle_timeout_minutes') ?? Company::DEFAULT_SETTINGS['idle_timeout_minutes']),
            ],
            'impersonator' => $impersonatorId ? User::find($impersonatorId)?->only(['id', 'name']) : null,
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }
}
