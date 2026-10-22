<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Attendance\Models\AttendanceGeofence;
use App\Modules\Attendance\Models\AttendanceOvertime;
use App\Modules\Attendance\Models\AttendanceShift;
use App\Modules\Attendance\Models\AttendanceTimesheet;
use Illuminate\Database\Seeder;

class AttendanceSetupSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedShifts($company);
            $this->seedGeofences($company);
            $this->seedOvertime($company);
            $this->seedTimesheets($company);
        });
    }

    private function seedShifts(Company $company): void
    {
        $shifts = [
            ['Day Shift',    '08:00', '17:00', 60,  ['Mon','Tue','Wed','Thu','Fri']],
            ['Morning Shift','06:00', '14:00', 30,  ['Mon','Tue','Wed','Thu','Fri','Sat']],
            ['Night Shift',  '22:00', '06:00', 30,  ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']],
            ['Half Day',     '08:00', '13:00', 0,   ['Mon','Tue','Wed','Thu','Fri']],
        ];

        foreach ($shifts as [$name, $start, $end, $break, $days]) {
            AttendanceShift::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'start_time'    => $start,
                    'end_time'      => $end,
                    'break_minutes' => $break,
                    'working_days'  => $days,
                    'grace_minutes' => 10,
                    'is_active'     => true,
                ]
            );
        }
    }

    private function seedGeofences(Company $company): void
    {
        $branches = \DB::table('branches')->where('company_id', $company->id)->get();

        $zones = [
            ['Head Office',  -1.2921, 36.8219, 200, 'Upper Hill, Nairobi'],
            ['Westlands',    -1.2676, 36.8062, 150, 'Westlands, Nairobi'],
            ['Mombasa CBD',  -4.0435, 39.6682, 200, 'Nyali, Mombasa'],
            ['Kisumu Office',-0.1022, 34.7617, 150, 'Milimani, Kisumu'],
        ];

        foreach ($zones as $i => [$name, $lat, $lng, $radius, $address]) {
            $branch = $branches->get($i);
            AttendanceGeofence::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'branch_id' => $branch?->id,
                    'address'   => $address,
                    'latitude'  => $lat,
                    'longitude' => $lng,
                    'radius_m'  => $radius,
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedOvertime(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->take(15)
            ->get();

        $statuses = ['pending', 'pending', 'approved', 'approved', 'approved', 'paid', 'rejected'];

        foreach ($employees as $i => $employee) {
            $numRecords = rand(1, 3);
            for ($j = 0; $j < $numRecords; $j++) {
                $date   = now()->subDays(rand(5, 60))->toDateString();
                $hours  = round(rand(1, 4) + (rand(0, 1) * 0.5), 1);
                $status = $statuses[array_rand($statuses)];

                if (AttendanceOvertime::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->where('date', $date)
                    ->exists()) {
                    continue;
                }

                AttendanceOvertime::create([
                    'company_id'  => $company->id,
                    'employee_id' => $employee->id,
                    'date'        => $date,
                    'day_type'    => 'working',
                    'minutes'     => (int) ($hours * 60),
                    'rate'        => collect([1.5, 2.0])->random(),
                    'amount'      => round($hours * rand(500, 1500)),
                    'reason'      => collect([
                        'Month-end reporting',
                        'Client deadline',
                        'System migration',
                        'Audit preparation',
                        'Emergency coverage',
                    ])->random(),
                    'status'      => $status,
                    'decided_by'  => in_array($status, ['approved', 'paid'])
                        ? User::withoutGlobalScopes()->where('company_id', $company->id)
                            ->whereHas('roles', fn ($q) => $q->where('name', 'supervisor'))
                            ->value('id')
                        : null,
                ]);
            }
        }
    }

    private function seedTimesheets(Company $company): void
    {
        $employees = Employee::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->take(20)
            ->get();

        $statuses = ['submitted', 'submitted', 'approved', 'approved', 'approved'];

        foreach ($employees as $employee) {
            // Seed last 3 weeks
            for ($w = 3; $w >= 1; $w--) {
                $monday = now()->startOfWeek()->subWeeks($w);
                $week   = $monday->format('Y-m-d');

                if (AttendanceTimesheet::withoutGlobalScopes()
                    ->where('employee_id', $employee->id)
                    ->where('week_start', $week)
                    ->exists()) {
                    continue;
                }

                $status        = $statuses[array_rand($statuses)];
                $totalMinutes  = rand(35, 45) * 60;
                $regularMins   = min($totalMinutes, 40 * 60);
                $otMins        = max(0, $totalMinutes - 40 * 60);

                AttendanceTimesheet::create([
                    'company_id'      => $company->id,
                    'employee_id'     => $employee->id,
                    'week_start'      => $week,
                    'worked_minutes'  => $totalMinutes,
                    'regular_minutes' => $regularMins,
                    'overtime_minutes'=> $otMins,
                    'status'          => $status,
                    'submitted_at'    => $monday->copy()->addDays(5)->setTime(9, 0),
                    'decided_at'      => $status === 'approved'
                        ? $monday->copy()->addDays(6)->setTime(10, 0)
                        : null,
                ]);
            }
        }
    }
}
