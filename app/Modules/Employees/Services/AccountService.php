<?php

namespace App\Modules\Employees\Services;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\ActivateAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * Login accounts for employees: creation, activation emails, role sync and deactivation.
 */
class AccountService
{
    /** Creates (or re-links) the login for an employee and optionally emails an activation link. */
    public function createLogin(Employee $employee, bool $sendActivation = true): User
    {
        if ($employee->user_id) {
            throw ValidationException::withMessages(['account' => "{$employee->full_name} already has a login."]);
        }
        if (! $employee->email) {
            throw ValidationException::withMessages(['email' => "{$employee->full_name} has no email address."]);
        }
        if (User::where('email', $employee->email)->exists()) {
            throw ValidationException::withMessages(['email' => "The email {$employee->email} is already used by another login."]);
        }

        $user = User::create([
            'company_id' => $employee->company_id,
            'name' => $employee->full_name,
            'email' => $employee->email,
            'is_active' => true,
        ]);
        $employee->forceFill(['user_id' => $user->id])->save();
        $employee->setRelation('user', $user);
        $this->syncRoles($employee);

        activity('employees')->performedOn($employee)->withProperties(['user_id' => $user->id])->log('Created login account');

        if ($sendActivation) {
            $this->sendActivation($user);
        }

        return $user;
    }

    public function sendActivation(User $user): void
    {
        $token = Password::broker('activations')->createToken($user);
        $user->notify(new ActivateAccount($token));
        activity('employees')->performedOn($user)->log('Sent account activation email');
    }

    /** Ensures the employee role, and the supervisor role exactly when the employee supervises. */
    public function syncRoles(Employee $employee): void
    {
        $user = $employee->user()->first();
        if (! $user) {
            return;
        }
        if (! $user->hasRole('employee')) {
            $user->assignRole('employee');
        }
        $this->syncSupervisorRole($employee, $user);
    }

    /**
     * Grants the supervisor role when the employee holds a supervisor position or has active direct reports;
     * revokes it otherwise (HR-FEATURES 1.6: promoting grants, removing revokes).
     */
    public function syncSupervisorRole(Employee $employee, ?User $user = null): void
    {
        $user ??= $employee->user()->first();
        if (! $user || $employee->status !== 'active') {
            return;
        }
        $supervises = (bool) $employee->position()->withoutGlobalScope('company')->value('is_supervisor')
            || Employee::withoutGlobalScope('company')->where('primary_supervisor_id', $employee->id)->where('status', 'active')->exists();

        if ($supervises && ! $user->hasRole('supervisor')) {
            $user->assignRole('supervisor');
            activity('employees')->performedOn($employee)->log('Granted supervisor role');
        } elseif (! $supervises && $user->hasRole('supervisor')) {
            $user->removeRole('supervisor');
            activity('employees')->performedOn($employee)->log('Revoked supervisor role');
        }
    }

    public function syncSupervisorRoleById(?int $employeeId): void
    {
        if ($employeeId && ($e = Employee::withoutGlobalScope('company')->find($employeeId))) {
            $this->syncSupervisorRole($e);
        }
    }

    /** Keeps the login name/email aligned with the employee record. */
    public function syncIdentity(Employee $employee): void
    {
        $user = $employee->user()->first();
        if (! $user) {
            return;
        }
        $changes = array_filter([
            'name' => $user->name !== $employee->full_name ? $employee->full_name : null,
            'email' => $employee->email && $user->email !== $employee->email ? $employee->email : null,
        ]);
        if ($changes) {
            if (isset($changes['email'])) {
                $user->email_verified_at = null;
            }
            $user->fill($changes)->save();
        }
    }

    /** Disables a login (exit): no sign-in, supervisor rights removed, sessions ended. */
    public function deactivate(User $user): void
    {
        $user->forceFill(['is_active' => false])->save();
        if ($user->hasRole('supervisor')) {
            $user->removeRole('supervisor');
        }
        // End active sessions — only when the session driver actually uses the sessions table.
        if (config('session.driver') === 'database') {
            try {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            } catch (\Throwable) {
                // Table may not exist in non-database session drivers; safe to ignore.
            }
        }
        $user->tokens()->delete();
    }

    /**
     * Moves a login onto a new employment record (re-instatement / transfer).
     * Company-specific approver roles are reset to the staff defaults; returns the roles removed.
     */
    public function moveLogin(User $user, Employee $from, Employee $to): array
    {
        $from->forceFill(['user_id' => null])->save();
        $previousRoles = $user->getRoleNames()->all();

        $user->forceFill(['company_id' => $to->company_id, 'is_active' => true, 'name' => $to->full_name])->save();
        if ($to->email && $to->email !== $user->email && ! User::where('email', $to->email)->whereKeyNot($user->id)->exists()) {
            $user->forceFill(['email' => $to->email])->save();
        }
        $to->forceFill(['user_id' => $user->id])->save();

        $user->syncRoles($user->hasRole('super-admin') ? ['super-admin', 'employee'] : ['employee']);
        $this->syncSupervisorRole($to, $user);

        return array_values(array_diff($previousRoles, $user->getRoleNames()->all()));
    }
}
