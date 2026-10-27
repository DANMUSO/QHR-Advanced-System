<?php

namespace App\Modules\Employees\Support;

use App\Models\Company;
use App\Models\Employee;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Resignation notice period (company setting `resignation_notice_days`, default 30): the last working day may be no
 * earlier than the notice date plus the notice days. HR may waive or shorten it when processing the exit.
 */
class ResignationNotice
{
    public const DEFAULT_DAYS = 30;

    public static function days(?int $companyId): int
    {
        $company = $companyId ? Company::withoutGlobalScopes()->find($companyId) : null;

        return max(0, (int) ($company?->setting('resignation_notice_days', self::DEFAULT_DAYS) ?? self::DEFAULT_DAYS));
    }

    public static function earliestLastDay(Employee $employee, CarbonInterface|string|null $noticeDate = null): Carbon
    {
        $notice = $noticeDate ? Carbon::parse($noticeDate) : today();

        return $notice->copy()->startOfDay()->addDays(self::days((int) $employee->company_id));
    }

    public static function message(Employee $employee, CarbonInterface|string|null $noticeDate, bool $self = true): string
    {
        $days = self::days((int) $employee->company_id);
        $earliest = self::earliestLastDay($employee, $noticeDate)->format('j M Y');

        return $self
            ? "With a {$days}-day notice period, your last working day can be no earlier than {$earliest}."
            : "The {$days}-day notice period ends on {$earliest}. Tick \"Waive notice\" to exit earlier.";
    }
}
