<?php

namespace App\Modules\Probation\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Probation workflow notification (mail + in-app bell). */
class ProbationNotification extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $message, public string $url = '/probation') {}

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
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => 'clipboard-check'];
    }
}
