<?php

namespace App\Modules\Attendance\Console;

use App\Modules\Attendance\Services\AttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ComputeAttendanceCommand extends Command
{
    protected $signature = 'attendance:compute
        {--date= : Day to compute (Y-m-d). Defaults to yesterday}
        {--from= : Start of a range (with --to)}
        {--to= : End of a range}
        {--company= : Limit to one company id}';

    protected $description = 'Build daily attendance summaries from punches, mark absentees and sync overtime claims';

    public function handle(AttendanceService $service): int
    {
        $from = CarbonImmutable::parse($this->option('from') ?? $this->option('date') ?? today()->subDay()->toDateString());
        $to = CarbonImmutable::parse($this->option('to') ?? $this->option('date') ?? $from->toDateString());
        if ($to->lt($from)) {
            $this->error('--to must be on or after --from.');

            return self::FAILURE;
        }
        if ($to->gt(today())) {
            $to = CarbonImmutable::today();
        }
        $company = $this->option('company') ? (int) $this->option('company') : null;

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $s = $service->computeDate($d, $company);
            $this->line(sprintf('%s  saved %d  absent %d  removed %d', $s['date'], $s['saved'], $s['absent'], $s['removed']));
        }
        $this->info('Attendance computed.');

        return self::SUCCESS;
    }
}
