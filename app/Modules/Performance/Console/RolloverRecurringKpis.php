<?php

namespace App\Modules\Performance\Console;

use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Kpi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RolloverRecurringKpis extends Command
{
    protected $signature = 'performance:kpi-rollover';

    protected $description = 'Clone ended recurring KPIs into the next period and reassign to active staff (§6.2, §12)';

    public function handle(): int
    {
        $rolled = 0;

        // Find recurring KPIs whose due_date has passed and haven't been rolled over yet today.
        Kpi::withoutGlobalScopes()
            ->where('is_recurring', true)
            ->whereNotNull('frequency_id')
            ->whereDate('due_date', '<', today())
            ->whereNull('rolled_over_at')
            ->with('frequency', 'goals.employee')
            ->each(function (Kpi $kpi) use (&$rolled) {
                $frequency = $kpi->frequency;
                if (! $frequency || ! $frequency->days) {
                    return;
                }

                $newDueDate = $kpi->due_date->addDays((int) $frequency->days);
                $newPeriodStart = $kpi->period_start
                    ? $kpi->period_start->addDays((int) $frequency->days)
                    : $newDueDate->copy()->subDays((int) $frequency->days - 1);

                DB::transaction(function () use ($kpi, $newDueDate, $newPeriodStart, &$rolled) {
                    // Clone the KPI for the new period.
                    $newKpi = $kpi->replicate(['rolled_over_at']);
                    $newKpi->due_date = $newDueDate;
                    $newKpi->period_start = $newPeriodStart;
                    $newKpi->rolled_over_at = null;
                    $newKpi->forceFill(['company_id' => $kpi->company_id]);
                    $newKpi->save();

                    // Reassign to the same active employees.
                    $kpi->goals()
                        ->with('employee')
                        ->get()
                        ->filter(fn (Goal $g) => $g->employee?->status === 'active')
                        ->each(function (Goal $oldGoal) use ($newKpi, $newDueDate) {
                            $exists = Goal::withoutGlobalScopes()
                                ->where('kpi_id', $newKpi->id)
                                ->where('employee_id', $oldGoal->employee_id)
                                ->exists();

                            if ($exists) {
                                return;
                            }

                            (new Goal([
                                'kpi_id'      => $newKpi->id,
                                'cycle_id'    => $oldGoal->cycle_id,
                                'employee_id' => $oldGoal->employee_id,
                                'assigned_by' => $oldGoal->assigned_by,
                                'target'      => $oldGoal->target,
                                'due_date'    => $newDueDate,
                                'status'      => 'cascaded',
                                'assignment_type' => $oldGoal->assignment_type,
                            ]))->forceFill(['company_id' => $oldGoal->company_id])->save();
                        });

                    // Mark the original KPI as rolled over.
                    $kpi->withoutGlobalScopes()->where('id', $kpi->id)
                        ->update(['rolled_over_at' => now()]);

                    $rolled++;
                });
            });

        $this->line("Rolled over {$rolled} recurring KPI(s).");

        return self::SUCCESS;
    }
}
