<?php

namespace App\Modules\Payroll\Notifications;

use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollRunAwaitingApproval extends Notification
{
    use Queueable;

    public function __construct(public PayrollRun $run) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Payroll awaiting approval: {$this->run->title}")
            ->line("{$this->run->title} has been reviewed and needs your approval.")
            ->line('Employees: '.$this->run->employee_count.' · Net pay: KES '.number_format($this->run->total_net, 2))
            ->action('Review run', url('/payroll/runs/'.$this->run->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Payroll awaiting approval',
            'message' => "{$this->run->title} · {$this->run->employee_count} employees · KES ".number_format($this->run->total_net),
            'url' => '/payroll/runs/'.$this->run->id,
            'icon' => 'wallet',
        ];
    }
}
