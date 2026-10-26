<?php

namespace App\Support;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * Who did an audited action. During "Sign in as", the causer is the impersonated account (the action really ran
 * as them) and the admin is recorded in properties.impersonated_by, so feeds read "System Administrator (as Zawadi)".
 */
class ActivityCauser
{
    /** Stamp the impersonating admin onto activities logged during an impersonation session. */
    public static function register(): void
    {
        Activity::creating(function (Activity $activity) {
            if (! app()->bound('request')) { // console jobs have no session, so the check below is enough
                return;
            }
            $request = request();
            if (! $request->hasSession() || ! ($adminId = $request->session()->get('impersonator_id'))) {
                return;
            }
            $activity->properties = collect($activity->properties)->put('impersonated_by', [
                'id' => (int) $adminId,
                'name' => User::query()->whereKey($adminId)->value('name'),
            ]);
        });
    }

    public static function label(Activity $activity): string
    {
        $name = $activity->causer?->name ?? 'System';
        $by = $activity->properties['impersonated_by'] ?? null;

        return $by ? ($by['name'] ?? 'An administrator').' (as '.$name.')' : $name;
    }
}
