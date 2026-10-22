<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePunch;
use Illuminate\Database\Seeder;

class AttendanceDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $employees = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->limit(30) // cap to avoid huge data sets
                ->get();

            // Seed last 30 working days
            $days = collect();
            $d = now()->subDays(1);
            while ($days->count() < 30) {
                if (! in_array($d->dayOfWeek, [0, 6])) {
                    $days->push(['date' => $d->copy(), 'day_type' => 'working']);
                }
                $d->subDay();
            }

            // Add ~4 weekend overtime days per employee
            $wd = now()->subDays(1);
            $weekendCount = 0;
            while ($weekendCount < 4) {
                if (in_array($wd->dayOfWeek, [0, 6])) {
                    $days->push(['date' => $wd->copy(), 'day_type' => 'weekend']);
                    $weekendCount++;
                }
                $wd->subDay();
            }

            foreach ($employees as $employee) {
                foreach ($days as $dayEntry) {
                    $day = $dayEntry['date'];
                    $dayType = $dayEntry['day_type'];

                    if (AttendanceDay::withoutGlobalScopes()
                        ->where('employee_id', $employee->id)
                        ->where('date', $day->toDateString())
                        ->exists()) {
                        continue;
                    }

                    $roll = rand(1, 10);
                    // Weekend days: only seed if employee worked overtime (roll > 3)
                    if ($dayType !== 'working' && $roll <= 3) {
                        continue;
                    }

                    if ($roll <= 1 && $dayType === 'working') {
                        // absent on working day only
                        AttendanceDay::create([
                            'company_id'      => $company->id,
                            'employee_id'     => $employee->id,
                            'date'            => $day->toDateString(),
                            'day_type'        => $dayType,
                            'computed_status' => 'absent',
                            'status'          => 'absent',
                            'worked_minutes'  => 0,
                        ]);
                        continue;
                    }

                    $lateMinutes = ($roll <= 2 && $dayType === 'working') ? rand(5, 45) : 0;
                    $inHour  = $dayType === 'working' ? 8 : 9;
                    $inMin   = $lateMinutes;
                    $outHour = $dayType === 'working' ? (17 + ($roll >= 9 ? rand(0, 2) : 0)) : rand(13, 16);
                    $outMin  = rand(0, 59);

                    $firstIn  = $day->copy()->setTime($inHour, $inMin);
                    $lastOut  = $day->copy()->setTime($outHour, $outMin);
                    $worked   = $firstIn->diffInMinutes($lastOut);
                    $overtime = $dayType === 'working' ? max(0, $worked - 540) : $worked;
                    $status   = $dayType !== 'working' ? 'present' : ($lateMinutes > 0 ? 'late' : 'present');

                    AttendancePunch::create([
                        'company_id'  => $company->id,
                        'employee_id' => $employee->id,
                        'punched_at'  => $firstIn,
                        'direction'   => 'in',
                        'source'      => 'web',
                    ]);
                    AttendancePunch::create([
                        'company_id'  => $company->id,
                        'employee_id' => $employee->id,
                        'punched_at'  => $lastOut,
                        'direction'   => 'out',
                        'source'      => 'web',
                    ]);

                    AttendanceDay::create([
                        'company_id'       => $company->id,
                        'employee_id'      => $employee->id,
                        'date'             => $day->toDateString(),
                        'day_type'         => $dayType,
                        'first_in'         => $firstIn,
                        'last_out'         => $lastOut,
                        'punch_count'      => 2,
                        'worked_minutes'   => $worked,
                        'regular_minutes'  => $dayType === 'working' ? min($worked, 540) : 0,
                        'late_minutes'     => $lateMinutes,
                        'overtime_minutes' => $overtime,
                        'computed_status'  => $status,
                        'status'           => $status,
                        'computed_at'      => now(),
                    ]);
                }
            }
        });
    }
}
