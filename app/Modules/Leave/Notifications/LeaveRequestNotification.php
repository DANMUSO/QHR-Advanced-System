<?php

namespace App\Modules\Leave\Notifications;

use App\Modules\Leave\Models\LeaveRequest;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every leave workflow notification (HR-FEATURES §11). The event decides wording, link and channels.
 */
class LeaveRequestNotification extends Notification
{
    use Queueable;

    /** event => [channels incl. sms?, icon] */
    private const EVENTS = [
        'handover_requested' => [true, 'ArrowLeftRight'],
        'supervisor_requested' => [true, 'CalendarClock'],
        'handover_approved' => [true, 'CheckCircle2'],
        'handover_rejected' => [true, 'XCircle'],
        'handover_amended' => [true, 'CalendarClock'],
        'approved' => [true, 'CheckCircle2'],
        'amended_approved' => [true, 'CheckCircle2'],
        'rejected' => [true, 'XCircle'],
        'edited' => [false, 'Pencil'],
        'cancelled' => [false, 'Ban'],
        'recalled' => [true, 'Undo2'],
        'reminder' => [false, 'Clock'],
        'step_approved' => [false, 'CheckCircle2'],
    ];

    public function __construct(public LeaveRequest $leave, public string $event, public ?string $note = null) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail', 'database'];
        if (self::EVENTS[$this->event][0] ?? false) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    private function name(): string
    {
        return $this->leave->employee?->full_name ?? 'An employee';
    }

    private function period(): string
    {
        $l = $this->leave;
        $days = rtrim(rtrim(number_format($l->days, 2), '0'), '.');
        $range = $l->start_date->isSameDay($l->end_date)
            ? $l->start_date->format('D j M Y')
            : $l->start_date->format('j M').' – '.$l->end_date->format('j M Y');

        return "{$l->leaveType?->name} · {$range} ({$days} day".($l->days == 1 ? '' : 's').')';
    }

    public function title(): string
    {
        return match ($this->event) {
            'handover_requested' => 'Handover request',
            'supervisor_requested' => 'Leave awaiting your approval',
            'handover_approved' => 'Handover accepted',
            'handover_rejected' => 'Handover declined',
            'handover_amended' => 'Handover dates amended',
            'approved' => 'Leave approved',
            'amended_approved' => 'Leave amended and approved',
            'rejected' => 'Leave rejected',
            'edited' => 'Leave request edited',
            'cancelled' => 'Leave request cancelled',
            'recalled' => 'You have been recalled from leave',
            'reminder' => 'Leave requests awaiting approval',
            'step_approved' => 'Leave approval step completed',
            default => 'Leave update',
        };
    }

    public function message(): string
    {
        $p = $this->period();
        $n = $this->name();
        $reason = $this->note ? " Reason: {$this->note}" : '';

        return match ($this->event) {
            'handover_requested' => "$n has asked you to cover their duties during $p.",
            'supervisor_requested' => "$n applied for $p.",
            'handover_approved' => "Your handover for $p was accepted.",
            'handover_rejected' => "Your handover for $p was declined.$reason",
            'handover_amended' => "Your handover colleague amended the dates: $p.$reason",
            'approved' => "Your $p has been approved.",
            'amended_approved' => "Your leave was amended and approved: $p.$reason",
            'rejected' => "Your $p was rejected.$reason",
            'edited' => "$n edited their leave request: $p.",
            'cancelled' => "$n cancelled their leave: $p.$reason",
            'recalled' => 'You have been recalled from '.$this->leave->leaveType?->name.'. Please report on '.($this->note ?? 'the next working day').'.',
            'reminder' => "$n's $p has been waiting for your decision for more than 2 days.",
            'step_approved' => "Your $p was approved by ".($this->note ?? 'an approver').' and is waiting for the next approver.',
            default => $p,
        };
    }

    public function url(): string
    {
        return match ($this->event) {
            'handover_requested' => '/leave?tab=handovers',
            'supervisor_requested', 'edited', 'reminder', 'cancelled' => '/leave/manage?tab=approvals',
            default => '/leave?tab=requests',
        };
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->title().' — '.$this->name())
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line($this->message());
        if ($this->leave->duties && $this->event === 'handover_requested') {
            $mail->line('Duties to cover: '.$this->leave->duties);
        }

        return $mail->action('Open in HR Advanced', url($this->url()));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => $this->title(),
            'message' => $this->message(),
            'url' => $this->url(),
            'icon' => self::EVENTS[$this->event][1] ?? 'CalendarDays',
            'leave_request_id' => $this->leave->id,
            'event' => $this->event,
        ];
    }

    public function toSms(object $notifiable): string
    {
        return 'HR: '.$this->message();
    }
}
