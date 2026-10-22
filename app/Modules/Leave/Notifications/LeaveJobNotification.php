<?php

namespace App\Modules\Leave\Notifications;

use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Summary of a monthly accrual or year-end carry-forward run, sent to leave administrators. */
class LeaveJobNotification extends Notification
{
    use Queueable;

    public function __construct(public string $job, public string $period, public string $companyName, public array $summary) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', SmsChannel::class];
    }

    private function title(): string
    {
        return $this->job === 'accrual' ? "Leave accrual completed ({$this->period})" : "Year-end leave carry-forward completed ({$this->period})";
    }

    private function message(): string
    {
        $s = $this->summary;

        return $this->job === 'accrual'
            ? "{$this->companyName}: {$s['employees']} employees credited, {$s['days']} days accrued."
            : "{$this->companyName}: {$s['employees']} balances processed, {$s['carried']} days carried forward, {$s['forfeited']} days forfeited.";
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title())->greeting('Hello '.($notifiable->name ?? '').',')->line($this->message());
        foreach (array_slice($this->summary['top_forfeitures'] ?? [], 0, 10) as $row) {
            $mail->line("• {$row['name']}: {$row['forfeited']} days forfeited");
        }

        return $mail->action('Open leave reports', url($this->job === 'accrual' ? '/leave/manage?tab=runs' : '/leave/reports?report=forfeiture'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->job === 'accrual' ? '/leave/manage?tab=runs' : '/leave/reports?report=forfeiture',
            'icon' => 'CalendarDays',
        ];
    }

    public function toSms(object $notifiable): string
    {
        return 'HR: '.$this->title().'. '.$this->message();
    }
}
