<?php

namespace App\Modules\Claims\Console;

use App\Models\Company;
use App\Models\User;
use App\Modules\Claims\Models\ClaimYearRun;
use Illuminate\Console\Command;

class ResetClaimBalances extends Command
{
    protected $signature = 'claims:reset-balances {--year= : Year to open (defaults to current year)} {--company= : Limit to one company id}';

    protected $description = 'Yearly claim balance year-open marker — claim balances are computed live so this records the new year and notifies admins (idempotent per company and year)';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: now()->year);
        $companyId = $this->option('company') ? (int) $this->option('company') : null;

        Company::query()->when($companyId, fn ($q) => $q->whereKey($companyId))->each(function (Company $company) use ($year) {
            if (ClaimYearRun::withoutGlobalScopes()->where('company_id', $company->id)->where('year', $year)->exists()) {
                $this->line("{$company->name}: already opened for {$year}");

                return;
            }

            // Count active employees for the summary
            $employees = \App\Models\Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->count();

            ClaimYearRun::create([
                'company_id' => $company->id,
                'year' => $year,
                'summary' => ['employees' => $employees, 'year' => $year],
                'ran_at' => now(),
            ]);

            // Notify HR admins
            try {
                $admins = User::where('company_id', $company->id)
                    ->where('is_active', true)
                    ->permission('claims.approve')
                    ->get();

                foreach ($admins as $admin) {
                    $admin->notify(new \Illuminate\Notifications\AnonymousNotifiable);
                }
            } catch (\Throwable) {
                // non-critical
            }

            $this->line("{$company->name}: claim year {$year} opened for {$employees} employee(s)");
        });

        return self::SUCCESS;
    }
}
