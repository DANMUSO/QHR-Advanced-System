<?php

namespace App\Modules\Employees\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Employee lifecycle notification (mail + in-app bell), e.g. contract expiry alerts. */
class EmployeeNotification extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $message, public string $url = '/employees', public string $icon = 'file-text') {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message)
            ->action('Open HR Advanced', rtrim(config('app.url'), '/').$this->url);
    }

    public function toArray(object $notifiable): array
    {
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => $this->icon];
    }
}
