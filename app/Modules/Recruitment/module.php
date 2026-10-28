<?php

use App\Modules\Employees\Events\EmployeeHired;
use App\Modules\Employees\Events\EmployeeReinstated;
use App\Modules\Employees\Events\EmployeeTransferred;
use App\Modules\Employees\Support\SideEffects;
use App\Modules\Recruitment\Http\Controllers\CareersController;
use App\Modules\Recruitment\Services\OnboardingChecklist;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

return [
    'name' => 'Recruitment',
    'order' => 85,
    'boot' => function () {
        view()->addNamespace('recruitment', __DIR__.'/resources/views');

        // Public careers page API (no login). Applications are throttled per IP.
        if (! app()->routesAreCached()) {
            Route::middleware('api')->prefix('api/careers')->group(function () {
                Route::get('/', [CareersController::class, 'index'])->middleware('throttle:60,1');
                Route::get('postings/{slug}', [CareersController::class, 'show'])->middleware('throttle:60,1');
                Route::post('postings/{slug}/apply', [CareersController::class, 'apply'])->middleware('throttle:5,10');
            });
        }

        // New hire, transfer into another company or re-instatement → onboarding / induction checklist from
        // the (new) company's templates; owners are notified.
        Event::listen([EmployeeHired::class, EmployeeTransferred::class, EmployeeReinstated::class], function ($event) {
            SideEffects::run('recruitment: onboarding checklist', fn () => app(OnboardingChecklist::class)->create($event->employee));
        });
    },
    'approval_providers' => [App\Modules\Recruitment\Dashboard\RequisitionApprovals::class],
];
