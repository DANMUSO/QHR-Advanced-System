<?php

namespace App\Modules\Documents\Console;

use App\Models\User;
use App\Modules\Documents\Models\EmployeeDocument;
use App\Modules\Documents\Notifications\DocumentExpiring;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Daily: warns the employee and HR (documents.manage) 30 and 7 days before an employee document expires.
 * Each alert is sent once per document (alert_30_sent_at / alert_7_sent_at; reset when the expiry date changes).
 */
class SendExpiryAlerts extends Command
{
    protected $signature = 'documents:expiry-alerts {--dry-run : List matches without notifying}';

    protected $description = 'Send 30- and 7-day expiry alerts for employee documents';

    public function handle(): int
    {
        $today = now()->startOfDay();
        $sent = 0;

        // Run the 7-day window first so a document first seen inside 7 days gets only the more urgent alert.
        foreach ([7 => 'alert_7_sent_at', 30 => 'alert_30_sent_at'] as $days => $column) {
            $docs = EmployeeDocument::withoutGlobalScope('company')->with('employee.user')
                ->whereNotNull('expiry_date')->whereNull($column)
                ->whereBetween('expiry_date', [$today->toDateString(), $today->copy()->addDays($days)->toDateString()])
                ->when($days === 30, fn ($q) => $q->whereNull('alert_7_sent_at'))
                ->get();

            foreach ($docs as $doc) {
                $employee = $doc->employee;
                if (! $employee || $employee->status !== 'active') {
                    continue;
                }
                $left = (int) $today->diffInDays($doc->expiry_date, false);
                $this->line(sprintf('[%d] %s — %s (%d day(s))', $doc->company_id, $employee->full_name, $doc->title, $left));
                if ($this->option('dry-run')) {
                    continue;
                }

                if ($employee->user?->is_active) {
                    $employee->user->notify(new DocumentExpiring($doc, $left, true));
                }
                $hr = User::permission('documents.manage')->where('company_id', $doc->company_id)->where('is_active', true)
                    ->when($employee->user_id, fn ($q, $id) => $q->whereKeyNot($id))->get();
                Notification::send($hr, new DocumentExpiring($doc, $left, false));

                $doc->forceFill([$column => now()] + ($days === 7 ? ['alert_30_sent_at' => $doc->alert_30_sent_at ?? now()] : []))->saveQuietly();
                $sent++;
            }
        }

        $this->info("Sent expiry alerts for {$sent} document(s).");

        return self::SUCCESS;
    }
}
