<?php

namespace App\Modules\Documents\Notifications;

use App\Modules\Documents\Models\CompanyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AcknowledgementReminder extends Notification
{
    use Queueable;

    public function __construct(public CompanyDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Reminder: please acknowledge \"{$this->document->title}\"")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("HR is waiting for you to confirm that you have read and understood \"{$this->document->title}\".")
            ->action('Read and acknowledge', url('/portal?tab=documents&doc='.$this->document->id));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Acknowledgement reminder',
            'message' => "Please read and acknowledge \"{$this->document->title}\".",
            'url' => '/portal?tab=documents&doc='.$this->document->id,
            'icon' => 'file-check',
        ];
    }
}
