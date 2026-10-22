<?php

namespace App\Dashboard\Widgets;

use App\Dashboard\Widget;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SecurityStatusWidget extends Widget
{
    public function key(): string
    {
        return 'security_status';
    }

    public function title(): string
    {
        return 'Account security';
    }

    public function data(User $user): array
    {
        $passwordAgeDays = $user->password_changed_at ? (int) $user->password_changed_at->diffInDays(now()) : null;
        $checks = [
            ['label' => 'Two-factor authentication', 'ok' => $user->hasTwoFactorEnabled()],
            ['label' => 'Password changed in the last 90 days', 'ok' => $passwordAgeDays !== null && $passwordAgeDays <= 90],
            ['label' => 'No failed sign-ins in the last 7 days', 'ok' => ! $user->loginActivities()->where('event', 'failed')->where('created_at', '>=', now()->subWeek())->exists()],
        ];

        return [
            'score' => (int) round(collect($checks)->where('ok', true)->count() / count($checks) * 100),
            'checks' => $checks,
            'active_sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'password_age_days' => $passwordAgeDays,
        ];
    }
}
