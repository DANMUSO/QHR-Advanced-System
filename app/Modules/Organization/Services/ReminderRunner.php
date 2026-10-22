<?php

namespace App\Modules\Organization\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Organization\Models\Reminder;
use App\Modules\Organization\Notifications\OrganizationNotice;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Evaluates a reminder rule for a given day and notifies the chosen recipients. */
class ReminderRunner
{
    /** Employees (and a short description) the rule fires for on $today. */
    public function matches(Reminder $reminder, CarbonInterface $today): Collection
    {
        $target = $today->copy()->startOfDay()->addDays($reminder->days_before);
        $base = Employee::withoutGlobalScope('company')->with('user', 'primarySupervisor.user')
            ->where('company_id', $reminder->company_id)->where('status', 'active');

        return match ($reminder->event) {
            'contract_expiry' => $base->whereDate('contract_end_date', $target)->get()
                ->map(fn ($e) => [$e, "{$e->full_name}'s contract ends on {$target->format('d M Y')}."]),
            'probation_end' => $base->whereDate('probation_end_date', $target)->whereIn('probation_status', ['on_probation', 'extended'])->get()
                ->map(fn ($e) => [$e, "{$e->full_name}'s probation ends on {$target->format('d M Y')}. A review is due."]),
            'birthday' => $base->whereMonth('date_of_birth', $target->month)->whereDay('date_of_birth', $target->day)->get()
                ->map(fn ($e) => [$e, $reminder->days_before ? "{$e->full_name}'s birthday is on {$target->format('d M')}." : "Today is {$e->full_name}'s birthday."]),
            'work_anniversary' => $base->whereMonth('date_of_joining', $target->month)->whereDay('date_of_joining', $target->day)
                ->whereYear('date_of_joining', '<', $target->year)->get()
                ->map(fn ($e) => [$e, "{$e->full_name} completes ".($target->year - $e->date_of_joining->year).' year(s) of service on '.$target->format('d M Y').'.']),
            'document_expiry' => $this->documentMatches($reminder, $target),
            default => collect(),
        };
    }

    /** Sends notifications; returns how many employees matched. */
    public function run(Reminder $reminder, CarbonInterface $today): int
    {
        $matches = $this->matches($reminder, $today);
        $hr = $reminder->recipients === 'hr' ? $this->hrUsers($reminder->company_id) : collect();
        $title = Reminder::EVENTS[$reminder->event] ?? 'Reminder';

        foreach ($matches as [$employee, $message]) {
            $recipients = match ($reminder->recipients) {
                'employee' => collect([$employee->user]),
                'supervisor' => collect([$employee->primarySupervisor?->user]),
                default => $hr,
            };
            $url = $reminder->recipients === 'employee' ? '/' : "/employees/{$employee->id}";
            foreach ($recipients->filter() as $user) {
                $user->notify(new OrganizationNotice($title, $message, $url, $reminder->channel, 'bell-ring'));
            }
        }

        $reminder->forceFill(['last_run_at' => now(), 'last_run_count' => $matches->count()])->saveQuietly();

        return $matches->count();
    }

    private function documentMatches(Reminder $reminder, CarbonInterface $target): Collection
    {
        if (! Schema::hasTable('employee_documents')) {
            return collect();
        }
        $rows = DB::table('employee_documents')->where('company_id', $reminder->company_id)->whereNull('deleted_at')->whereDate('expiry_date', $target)->get(['employee_id', 'title']);
        $employees = Employee::withoutGlobalScope('company')->with('user', 'primarySupervisor.user')->whereIn('id', $rows->pluck('employee_id'))->where('status', 'active')->get()->keyBy('id');

        return $rows->filter(fn ($r) => isset($employees[$r->employee_id]))
            ->map(fn ($r) => [$employees[$r->employee_id], "{$employees[$r->employee_id]->full_name}'s document \"".($r->title ?? 'document')."\" expires on {$target->format('d M Y')}."])
            ->values();
    }

    private function hrUsers(int $companyId): Collection
    {
        return User::permission('employees.view')->where('company_id', $companyId)->where('is_active', true)->get();
    }
}
