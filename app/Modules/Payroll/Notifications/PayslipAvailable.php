<?php

namespace App\Modules\Payroll\Notifications;

use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Payslip ready. withPdf = bulk email with the PDF attached (queued); otherwise an in-app notice only. */
class PayslipAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public PayrollRunEmployee $line, public bool $withPdf = true) {}

    public function via(object $notifiable): array
    {
        return $this->withPdf ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $service = app(PayslipService::class);
        $line = PayrollRunEmployee::withoutGlobalScopes()->with(['run', 'employee'])->find($this->line->id);
        $period = $line->run->period->format('F Y');

        [$bytes, $protected] = $service->emailPdf($line);

        $mail = (new MailMessage)
            ->subject("Your payslip for {$period}")
            ->greeting('Hello '.($line->employee?->first_name ?? $notifiable->name).',')
            ->line("Your payslip for {$period} is attached. Pay date: ".$line->run->pay_date?->format('d M Y').'.');
        if ($protected) {
            $mail->line('The PDF is password protected: open it with your national ID number.');
        }
        $mail->action('View payslips', url('/portal'))
            ->line('Payslips are confidential. Contact the payroll office if anything looks wrong.');

        $line->update(['emailed_at' => now()]);

        return $mail->attachData($bytes, $service->filename($line), ['mime' => 'application/pdf']);
    }

    public function toArray(object $notifiable): array
    {
        $line = PayrollRunEmployee::withoutGlobalScopes()->with('run')->find($this->line->id);

        return [
            'title' => 'Payslip available',
            'message' => 'Your payslip for '.($line?->run?->period?->format('F Y') ?? 'the period').' is ready to download.',
            'url' => '/portal?payslip='.$this->line->id,
            'icon' => 'wallet',
        ];
    }
}
