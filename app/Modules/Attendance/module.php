<?php

use App\Models\Employee;
use App\Modules\Attendance\Console\ComputeAttendanceCommand;
use App\Modules\Attendance\Dashboard\AttendanceApprovalProvider;
use App\Modules\Attendance\Dashboard\MyAttendanceWeekWidget;
use App\Modules\Attendance\Dashboard\TeamAttendanceTodayWidget;
use App\Modules\Attendance\Http\Controllers\DeviceApiController;
use App\Modules\Attendance\Http\Middleware\AuthenticateDevice;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\AttendanceTimesheet;
use App\Modules\Attendance\Payroll\AttendancePayrollInputs;
use App\Modules\Attendance\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

return [
    'name' => 'Attendance',
    'order' => 50,

    'widgets' => [MyAttendanceWeekWidget::class, TeamAttendanceTodayWidget::class],
    'approval_providers' => [AttendanceApprovalProvider::class],
    'payroll_inputs' => [AttendancePayrollInputs::class],

    // Nightly: finalise yesterday (absentees, incomplete days, overtime claims).
    'schedule' => fn (Schedule $s) => $s->command('attendance:compute')->dailyAt('00:15')->withoutOverlapping()->onOneServer(),

    // My Team widget: live present / late / absent / on_leave for today.
    'team_status' => function (Collection $employeeIds) {
        if ($employeeIds->isEmpty()) {
            return [];
        }
        $employees = Employee::withoutGlobalScope('company')->whereIn('id', $employeeIds->all())->get(['id', 'company_id', 'branch_id', 'first_name', 'last_name']);
        $out = [];
        foreach (app(AttendanceService::class)->liveStatuses($employees, CarbonImmutable::today()) as $id => $s) {
            if (in_array($s['status'], ['present', 'late', 'absent', 'on_leave'], true)) {
                $out[$id] = $s['status'];
            }
        }

        return $out;
    },

    'demo_seeder' => \Database\Seeders\AttendanceDataSeeder::class,

    'boot' => function () {
        app()->singleton(AttendanceService::class);

        // Roster or calendar changes reopen finished days, so the next register read / payroll run re-marks absentees.
        $reopen = function (?int $companyId, $from) {
            if (! $from) {
                return;
            }
            $from = CarbonImmutable::parse($from);
            $ids = $companyId ? [$companyId] : \App\Models\Company::query()->pluck('id')->all();
            foreach ($ids as $id) {
                app(AttendanceService::class)->invalidateFinalised((int) $id, $from);
            }
        };
        Employee::saved(function (Employee $e) use ($reopen) {
            if ($e->wasRecentlyCreated || $e->wasChanged(['status', 'date_of_joining'])) {
                $dates = array_filter([$e->date_of_joining, $e->getOriginal('date_of_joining')]);
                $reopen($e->company_id, $dates ? min(array_map(fn ($d) => CarbonImmutable::parse($d)->toDateString(), $dates)) : today()->subDays(62));
            }
        });
        if (class_exists(\App\Modules\Employees\Models\ExitRecord::class)) {
            \App\Modules\Employees\Models\ExitRecord::saved(function ($x) use ($reopen) {
                $dates = array_filter([$x->exit_date, $x->getOriginal('exit_date')]);
                $reopen(Employee::withoutGlobalScope('company')->whereKey($x->employee_id)->value('company_id'),
                    $dates ? min(array_map(fn ($d) => CarbonImmutable::parse($d)->toDateString(), $dates)) : null);
            });
        }
        \App\Models\Holiday::saved(fn ($h) => $reopen($h->company_id, $h->recurring ? today()->subDays(62) : $h->date));
        \App\Models\Holiday::deleted(fn ($h) => $reopen($h->company_id, $h->recurring ? today()->subDays(62) : $h->date));

        Employee::resolveRelationUsing('attendanceDays', fn ($e) => $e->hasMany(AttendanceDay::class));
        Employee::resolveRelationUsing('attendancePunches', fn ($e) => $e->hasMany(AttendancePunch::class));
        Employee::resolveRelationUsing('overtimes', fn ($e) => $e->hasMany(AttendanceOvertime::class, 'employee_id'));
        Employee::resolveRelationUsing('timesheets', fn ($e) => $e->hasMany(AttendanceTimesheet::class));

        \Illuminate\Console\Application::starting(fn ($artisan) => $artisan->resolve(ComputeAttendanceCommand::class));

        // Leave approved / cancelled / recalled → recompute that employee's attendance days (by class name, so
        // Attendance does not depend on the Leave module being installed). Leave dispatches it via SideEffects.
        \Illuminate\Support\Facades\Event::listen('App\Modules\Leave\Events\LeaveDatesChanged', function ($e) {
            app(AttendanceService::class)->recomputeEmployeeRange($e->employeeId, CarbonImmutable::parse($e->from), CarbonImmutable::parse($e->to));
        });

        // Payroll run approved → the overtime it paid is marked "paid" (by class name: no hard Payroll dependency).
        \Illuminate\Support\Facades\Event::listen('App\Modules\Payroll\Events\PayrollRunApproved', [\App\Modules\Attendance\Listeners\MarkOvertimePaid::class, 'handle']);

        // routes/api.php declares placeholder StubController routes for some attendance URIs *after* the module
        // routes, which would shadow the real endpoints. Re-apply this module's routes once all route files have
        // loaded so the real controllers win. Harmless (identical routes replace themselves) once the stubs are removed.
        app()->booted(function () {
            if (! app()->routesAreCached()) {
                Route::middleware(['api', 'auth:sanctum', 'account.secure'])->prefix('api')->group(__DIR__.'/routes.php');
            }
        });

        // Biometric device API — token authenticated, outside the user session.
        RateLimiter::for('attendance-device', fn (Request $r) => Limit::perMinute(120)->by('device:'.sha1((string) $r->bearerToken() ?: $r->ip())));
        Route::middleware(['api', 'throttle:attendance-device', AuthenticateDevice::class])
            ->prefix('api/attendance/device')
            ->group(function () {
                Route::post('punches', [DeviceApiController::class, 'punches']);
                Route::get('staff', [DeviceApiController::class, 'staff']);
                Route::post('heartbeat', [DeviceApiController::class, 'heartbeat']);
            });
    },
];
