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
