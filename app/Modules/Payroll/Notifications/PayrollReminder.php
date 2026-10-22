<?php

namespace App\Modules\Payroll\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PayrollReminder extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $message, public string $url = '/payroll') {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->title)->line($this->message)->action('Open payroll', url($this->url));
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => 'alarm-clock'];
    }
}
