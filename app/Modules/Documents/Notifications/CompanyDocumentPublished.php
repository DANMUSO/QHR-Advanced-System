<?php

namespace App\Modules\Documents\Notifications;

use App\Modules\Documents\Models\CompanyDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A new memo / newsletter / policy was published to the staff member. Mail only when acknowledgement is required. */
class CompanyDocumentPublished extends Notification
{
    use Queueable;

    public function __construct(public CompanyDocument $document) {}

    public function via(object $notifiable): array
    {
        return $this->document->requires_acknowledgement ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $d = $this->document;

        return (new MailMessage)
            ->subject("New {$d->category}: {$d->title}")
            ->greeting('Hello '.$notifiable->name.',')
            ->line("A new {$d->category} \"{$d->title}\" has been published.")
            ->line('Please read it and confirm that you have read and understood it.')
            ->action('Read the document', url('/portal?tab=documents&doc='.$d->id));
    }

    public function toArray(object $notifiable): array
    {
        $d = $this->document;

        return [
            'title' => 'New '.ucfirst($d->category).' published',
            'message' => $d->title.($d->requires_acknowledgement ? ' — acknowledgement required' : ''),
            'url' => '/portal?tab=documents&doc='.$d->id,
            'icon' => 'file-text',
        ];
    }
}
