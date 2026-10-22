<?php

namespace App\Modules\Compliance\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Compliance\Models\ComplianceRecord;
use App\Modules\Compliance\Models\DisciplinaryCase;
use App\Modules\Compliance\Models\WarningLetter;
use App\Modules\Compliance\Notifications\ComplianceNotification;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Services\LetterService;
use App\Modules\Performance\Models\Pip;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/** Shared compliance actions: reference numbers, PIP escalation, warning letters and notices. */
class ComplianceService
{
    /** Assigns the human reference (DC-2026-0001 …) and saves, retrying on a rare collision. */
    public static function saveWithNumber(ComplianceRecord $record, string $column, string $prefix): void
    {
        for ($attempt = 0; ; $attempt++) {
            $next = (int) $record->newQueryWithoutScopes()->max('id') + 1 + $attempt;
            $record->{$column} = sprintf('%s-%s-%04d', $prefix, now()->year, $next);
            try {
                $record->save();

                return;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 5) {
                    throw $e;
                }
            }
        }
    }

    /** A PIP closed as "escalated" opens a disciplinary case linked to it; HR is notified. Idempotent per PIP. */
    public function openCaseFromPip(Pip $pip, ?User $by): DisciplinaryCase
    {
        $existing = DisciplinaryCase::withoutGlobalScope('company')->where('pip_id', $pip->id)->first();
        if ($existing) {
            return $existing;
        }
        $pip->loadMissing('employee');
        $case = new DisciplinaryCase([
            'employee_id' => $pip->employee_id, 'pip_id' => $pip->id,
            'offence' => 'Unsatisfactory performance (escalated PIP)',
            'description' => trim("PIP {$pip->start_date?->format('d M Y')} – {$pip->end_date?->format('d M Y')} was closed as escalated.\n\nConcern: {$pip->concern}\n\nClosing comment: {$pip->outcome_comment}"),
            'reported_date' => today(), 'status' => 'open', 'reported_by_user_id' => $by?->id,
        ]);
        $case->forceFill(['company_id' => $pip->company_id]);
        self::saveWithNumber($case, 'case_number', 'DC');
        activity('compliance')->performedOn($case)->causedBy($by)->withProperties(['pip_id' => $pip->id])->log('Disciplinary case opened from escalated PIP');

        $name = $pip->employee?->full_name ?? 'An employee';
        $this->notifyHr((int) $pip->company_id, 'PIP escalated to a disciplinary case',
            "{$name}'s performance improvement plan was escalated. Disciplinary case {$case->case_number} has been opened.", '/compliance?tab=disciplinary', [$by?->id]);

        return $case;
    }

    /** Generate the Employees "warning" letter for a warning and link it. */
    public function generateLetter(WarningLetter $warning, ?User $by): EmployeeLetter
    {
        if ($warning->letter_id && ($letter = EmployeeLetter::withoutGlobalScope('company')->find($warning->letter_id))) {
            return $letter;
        }
        $employee = Employee::withoutGlobalScope('company')->findOrFail($warning->employee_id);
        $level = ['verbal' => 'Verbal', 'written' => 'Written', 'final' => 'Final written'][$warning->level] ?? 'Written';
        $notes = "{$level} warning.\n\n{$warning->reason}".($warning->expiry_date ? "\n\nThis warning remains on your file until {$warning->expiry_date->format('jS F Y')}." : '');
        $letter = app(LetterService::class)->generate($employee, 'warning', $warning->issued_date?->toDateString(), $notes, $by);
        $warning->forceFill(['letter_id' => $letter->id])->save();

        return $letter;
    }

    public static function letterUrl(?int $letterId): ?string
    {
        return $letterId ? '/api/employees/letters/'.$letterId.'/download' : null;
    }

    public function notifyEmployee(?Employee $employee, string $title, string $message, string $url = '/portal'): void
    {
        $user = $employee?->user;
        if (! $user) {
            return;
        }
        try {
            $user->notify(new ComplianceNotification($title, $message, $url));
        } catch (\Throwable $e) {
            report($e); // a mail failure must not break the workflow
        }
    }

    /** Notify the company's HR (employees.update), skipping the given user ids. */
    public function notifyHr(int $companyId, string $title, string $message, string $url, array $except = []): void
    {
        foreach ($this->hrUsers($companyId)->reject(fn (User $u) => in_array($u->id, array_filter($except), true)) as $user) {
            try {
                $user->notify(new ComplianceNotification($title, $message, $url));
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** @return Collection<int, User> */
    private function hrUsers(int $companyId): Collection
    {
        return User::permission('employees.update')->where('company_id', $companyId)->where('is_active', true)->get();
    }
}
