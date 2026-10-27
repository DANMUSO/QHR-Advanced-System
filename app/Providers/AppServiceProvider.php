<?php

namespace App\Providers;

use App\Services\Sms\SmsSender;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SmsSender::class);
    }

    public function boot(): void
    {
        // Actions taken during "Sign in as" are attributed to the admin as well as the impersonated account.
        \App\Support\ActivityCauser::register();

        // JSON dates carry the company's local offset (2026-10-27T00:00:00+03:00) instead of UTC "Z". The default
        // UTC form turned a Nairobi date into the previous day anywhere the UI reads the date part.
        \Illuminate\Support\Facades\Date::serializeUsing(fn ($date) => $date->format('Y-m-d\TH:i:sP'));

        // Company-wide password policy. Breached-password checks run in production (k-anonymity lookup).
        Password::defaults(function () {
            $rule = Password::min(10)->letters()->mixedCase()->numbers()->symbols();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });

        // Super admins pass every permission check.
        Gate::before(fn ($user) => $user->hasRole('super-admin') ? true : null);

        // Self-contained HR modules (app/Modules/*).
        $this->loadMigrationsFrom(ModuleRegistry::migrationPaths());
        foreach (ModuleRegistry::callbacks('boot') as $boot) {
            $boot();
        }
    }
}
