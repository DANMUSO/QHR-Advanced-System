<?php

return [
    'name' => 'Compliance',
    'order' => 76,
    'demo_seeder' => App\Modules\Compliance\Database\DemoSeeder::class,

    'boot' => function () {
        // A Warning / Final warning outcome issues the linked warning and its letter however the case is saved
        // (case screen, PIP escalation, import). Idempotent per case.
        App\Modules\Compliance\Models\DisciplinaryCase::saved(function (App\Modules\Compliance\Models\DisciplinaryCase $case) {
            if ($case->wasChanged('outcome') || ($case->wasRecentlyCreated && $case->outcome)) {
                App\Modules\Employees\Support\SideEffects::run('compliance: warning from disciplinary outcome',
                    fn () => app(App\Modules\Compliance\Services\ComplianceService::class)->warningFromCase($case, auth()->user()));
            }
        });
    },
];
