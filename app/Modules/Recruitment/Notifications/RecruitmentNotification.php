<?php

namespace App\Modules\Recruitment\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Recruitment / onboarding notification (mail + in-app bell). */
class RecruitmentNotification extends Notification
{
    use Queueable;

    public function __construct(public string $title, public string $message, public string $url = '/recruitment') {}

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
        return ['title' => $this->title, 'message' => $this->message, 'url' => $this->url, 'icon' => 'user-plus'];
    }
}
