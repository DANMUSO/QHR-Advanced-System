<?php

namespace App\Modules\Attendance\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells an employee their overtime claim or weekly timesheet was approved or rejected. */
class AttendanceDecisionNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  overtime | timesheet
     * @param  string  $status  approved | rejected
     * @param  string  $what  e.g. "2.5 h overtime on Tue 14 Oct" or "timesheet for 13 Oct – 19 Oct 2026"
     */
    public function __construct(public string $kind, public string $status, public string $what, public ?string $reason = null, public ?string $decider = null) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function title(): string
    {
        return ($this->kind === 'overtime' ? 'Overtime ' : 'Timesheet ').$this->status;
    }

    private function message(): string
    {
        return 'Your '.$this->what.' was '.$this->status.($this->decider ? " by {$this->decider}" : '').'.'
            .($this->reason ? " Reason: {$this->reason}" : '');
    }

    private function url(): string
    {
        return $this->kind === 'overtime' ? '/attendance' : '/attendance?tab=timesheets';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title())
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message())
            ->action('Open My Attendance', url($this->url()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
            'icon' => $this->status === 'approved' ? 'CheckCircle2' : 'XCircle',
        ];
    }
}
