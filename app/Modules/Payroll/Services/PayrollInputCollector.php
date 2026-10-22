<?php

namespace App\Modules\Payroll\Services;

use App\Contracts\PayrollInputProvider;
use App\Models\Employee;
use App\Support\ModuleRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

/**
 * Sums payroll_inputs from every module provider (Leave, Attendance, ...). Works with no providers registered;
 * a failing provider is logged and skipped so one module cannot block payroll.
 */
class PayrollInputCollector
{
    private ?array $providers = null;

    /** @return PayrollInputProvider[] */
    public function providers(): array
    {
        return $this->providers ??= collect(ModuleRegistry::collect('payroll_inputs'))
            ->map(function ($class) {
                try {
                    return app($class);
                } catch (\Throwable $e) {
                    Log::warning("Payroll input provider {$class} could not be resolved: {$e->getMessage()}");

                    return null;
                }
            })
            ->filter(fn ($p) => $p instanceof PayrollInputProvider)
            ->values()->all();
    }

    public function collect(Employee $employee, CarbonInterface $start, CarbonInterface $end): array
    {
        $totals = [];
        foreach ($this->providers() as $provider) {
            try {
                foreach ((array) $provider->inputsFor($employee, $start, $end) as $key => $value) {
                    if (is_numeric($value)) {
                        $totals[$key] = round(($totals[$key] ?? 0) + (float) $value, 2);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Payroll input provider '.get_class($provider)." failed for employee {$employee->id}: {$e->getMessage()}");
            }
        }

        return $totals;
    }
}
