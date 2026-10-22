<?php

namespace App\Modules\Organization\Support;

use App\Models\Company;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Resolves which company an organisation-setup request reads from or writes to. */
class CompanyContext
{
    /**
     * Company id for a new record. Super admins pass `company_id` (or use the X-Company-Id scope);
     * everyone else is pinned to their own company.
     */
    public static function forWrite(Request $request): int
    {
        if (Tenancy::isGlobal()) {
            $id = $request->integer('company_id') ?: Tenancy::companyId();
            if (! $id || ! Company::whereKey($id)->exists()) {
                throw ValidationException::withMessages(['company_id' => 'Select a company.']);
            }

            return $id;
        }

        $id = $request->user()->company_id;
        abort_unless($id, 403, 'Your account is not linked to a company.');

        return $id;
    }

    /** Company for single-company screens (settings): super admins pick via ?company_id or the scope header. */
    public static function current(Request $request): Company
    {
        if (Tenancy::isGlobal()) {
            $id = $request->integer('company_id') ?: Tenancy::companyId();
            if (! $id) {
                throw ValidationException::withMessages(['company_id' => 'Select a company.']);
            }

            return Company::findOrFail($id);
        }

        abort_unless($request->user()->company_id, 403, 'Your account is not linked to a company.');

        return Company::findOrFail($request->user()->company_id);
    }

    public static function canAccess(Request $request, ?int $companyId): bool
    {
        return Tenancy::isGlobal() || ($companyId !== null && $companyId === $request->user()->company_id);
    }
}
