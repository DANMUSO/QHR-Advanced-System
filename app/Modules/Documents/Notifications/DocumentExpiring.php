<?php

namespace App\Modules\Documents\Notifications;

use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to HR and the employee 30 and 7 days before a document expires. */
class DocumentExpiring extends Notification
{
    use Queueable;

    public function __construct(public EmployeeDocument $document, public int $days, public bool $forOwner) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    private function url(): string
    {
        return $this->forOwner ? '/portal?tab=documents' : '/documents/expiry';
    }

    private function subject(): string
    {
        $who = $this->forOwner ? 'Your' : $this->document->employee->full_name.'\'s';

        return "{$who} document \"{$this->document->title}\" expires in {$this->days} day".($this->days === 1 ? '' : 's');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->subject().' (on '.$this->document->expiry_date->format('d M Y').').')
            ->line($this->forOwner ? 'Please obtain a renewed copy and share it with HR.' : 'Please follow up on the renewal.')
            ->action('Open documents', url($this->url()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Document expiring soon',
            'message' => $this->subject(),
            'url' => $this->url(),
            'icon' => 'file-clock',
        ];
    }
}
