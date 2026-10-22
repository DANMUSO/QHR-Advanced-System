<?php

namespace App\Modules\Probation\Console;

use App\Models\User;
use App\Modules\Probation\Models\ProbationReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;

class AlertProbationEnding extends Command
{
    protected $signature = 'probation:alert';

    protected $description = 'Send 7/3/1-day alerts to HR and supervisors for probations ending soon';

    public function handle(): int
    {
        $sent = 0;
        foreach ([7, 3, 1] as $days) {
            $target = today()->addDays($days)->toDateString();

            ProbationReview::withoutGlobalScopes()
                // Every undecided review, including a fresh cycle after an extension.
                ->whereIn('status', ['pending', 'in_review', 'hr_review'])
                ->whereHas('employee', fn ($q) => $q->withoutGlobalScopes()->whereIn('probation_status', ['on_probation', 'extended'])->whereIn('status', ['active', 'suspended']))
                ->whereDate('probation_end_date', $target)
                ->with('employee.user', 'employee.primarySupervisor.user')
                ->each(function (ProbationReview $review) use ($days, &$sent) {
                    $employee = $review->employee;
                    if (! $employee) {
                        return;
                    }

                    // Notify HR admins in the company
                    $recipients = User::where('company_id', $employee->company_id)
                        ->where('is_active', true)
                        ->permission('employees.update')
                        ->get();

                    // Also notify the primary supervisor
                    if ($sup = $employee->primarySupervisor?->user) {
                        $recipients = $recipients->push($sup)->unique('id');
                    }

                    if ($recipients->isEmpty()) {
                        return;
                    }

                    Notification::send($recipients, new class($employee, $days) extends BaseNotification {
                        public function __construct(private $employee, private int $days) {}

                        public function via($notifiable): array { return ['mail']; }

                        public function toMail($notifiable): MailMessage
                        {
                            return (new MailMessage)
                                ->subject("Probation ending in {$this->days} day(s): {$this->employee->full_name}")
                                ->line("{$this->employee->full_name}'s probation period ends in {$this->days} day(s).")
                                ->line('Please complete the probation review before the end date.')
                                ->action('View Employee', url("/employees/{$this->employee->id}"));
                        }
                    });

                    $sent++;
                });
        }

        $this->line("Sent {$sent} probation alert(s).");

        return self::SUCCESS;
    }
}
