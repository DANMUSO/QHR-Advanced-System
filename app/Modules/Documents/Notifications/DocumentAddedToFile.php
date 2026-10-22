<?php

namespace App\Modules\Documents\Notifications;

use App\Modules\Documents\Models\EmployeeDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Tells an employee that HR added a document to their personal file. */
class DocumentAddedToFile extends Notification
{
    use Queueable;

    public function __construct(public EmployeeDocument $document) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'New document in your file',
            'message' => "HR added \"{$this->document->title}\" to your personal file.",
            'url' => '/portal?tab=documents',
            'icon' => 'file-text',
        ];
    }
}
