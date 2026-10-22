<?php

namespace App\Modules\Employees\Services;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeLetter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

/**
 * HR letters generated from employee data and rendered to PDF (dompdf).
 * The rendered text is stored with the letter so a re-download always shows what was issued.
 */
class LetterService
{
    public const TEMPLATES = [
        'employment_confirmation' => ['Confirmation of Employment', 'Confirms the employee works here (for banks, embassies, landlords).'],
        'introduction' => ['Introduction Letter', 'Introduces the employee to a third party.'],
        'probation_confirmation' => ['Confirmation of Appointment', 'Confirms the employee after successful probation.'],
        'promotion' => ['Promotion Letter', 'Notifies the employee of a promotion to their current position.'],
        'transfer' => ['Transfer Letter', 'Notifies the employee of a transfer to their current branch / department.'],
        'warning' => ['Warning Letter', 'Formal written warning. Put the details of the issue in the notes.'],
        'certificate_of_service' => ['Certificate of Service', 'Statutory certificate issued on exit (Employment Act s.51).'],
        'no_objection' => ['No Objection Letter', 'States the company has no objection to the stated request (notes).'],
    ];

    public static function templates(): array
    {
        return collect(self::TEMPLATES)->map(fn ($t, $key) => ['key' => $key, 'name' => $t[0], 'description' => $t[1]])->values()->all();
    }

    public function generate(Employee $employee, string $template, ?string $effectiveDate, ?string $notes, ?User $by): EmployeeLetter
    {
        $employee->loadMissing([
            'position' => fn ($q) => $q->withoutGlobalScope('company'), 'department' => fn ($q) => $q->withoutGlobalScope('company'),
            'branch' => fn ($q) => $q->withoutGlobalScope('company'), 'exitRecord',
        ]);
        $date = $effectiveDate ? Carbon::parse($effectiveDate) : now();
        [$subject, $body] = $this->compose($employee, $template, $date, $notes);

        $letter = new EmployeeLetter([
            'employee_id' => $employee->id, 'template' => $template, 'reference' => $this->nextReference($employee->company_id),
            'subject' => $subject, 'body' => $body, 'effective_date' => $date->toDateString(), 'notes' => $notes, 'generated_by' => $by?->id,
        ]);
        $letter->forceFill(['company_id' => $employee->company_id])->save();
        activity('employees')->performedOn($employee)->causedBy($by)->withProperties(['letter_id' => $letter->id, 'template' => $template])->log('Generated letter: '.$subject);

        return $letter;
    }

    public function pdf(EmployeeLetter $letter)
    {
        $employee = $letter->employee()->withoutGlobalScope('company')->first();

        return Pdf::loadView('employees::letter', [
            'letter' => $letter, 'employee' => $employee, 'company' => Company::withTrashed()->find($letter->company_id),
            'signatory' => $letter->generator,
        ])->setPaper('a4');
    }

    /** @return array{0: string, 1: string} subject and body paragraphs separated by blank lines */
    private function compose(Employee $e, string $template, Carbon $date, ?string $notes): array
    {
        $company = Company::withTrashed()->find($e->company_id)?->name ?? 'the Company';
        $name = trim("{$e->first_name} {$e->other_names} {$e->last_name}");
        $title = $e->gender === 'female' ? 'Ms.' : ($e->gender === 'male' ? 'Mr.' : '');
        $he = $e->gender === 'female' ? 'she' : ($e->gender === 'male' ? 'he' : 'they');
        $his = $e->gender === 'female' ? 'her' : ($e->gender === 'male' ? 'his' : 'their');
        $him = $e->gender === 'female' ? 'her' : ($e->gender === 'male' ? 'him' : 'them');
        $position = $e->position?->title ?? 'staff member';
        $dept = $e->department?->name;
        $joined = $e->date_of_joining?->format('jS F Y') ?? 'their date of joining';
        $id = $e->national_id ? " (ID No. {$e->national_id})" : '';
        $where = $dept ? "{$position} in the {$dept} department" : $position;
        $eff = $date->format('jS F Y');
        $extra = $notes ? "\n\n".trim($notes) : '';
        $terms = match ($e->contract_type) {
            'permanent' => 'on permanent and pensionable terms',
            'contract' => 'on a fixed-term contract'.($e->contract_end_date ? ' ending on '.$e->contract_end_date->format('jS F Y') : ''),
            'casual' => 'on casual terms', 'intern' => 'as an intern', default => '',
        };

        return match ($template) {
            'employment_confirmation' => ['CONFIRMATION OF EMPLOYMENT: '.strtoupper($name),
                "This is to confirm that {$title} {$name}{$id} is an employee of {$company}, having joined on {$joined}. {$this->cap($he)} currently serves as {$where}, {$terms}.\n\nThis letter is issued at the request of the employee for whatever lawful purpose it may serve. The Company accepts no liability arising from its use.{$extra}"],
            'introduction' => ['LETTER OF INTRODUCTION: '.strtoupper($name),
                "We hereby introduce {$title} {$name}{$id}, who is employed by {$company} as {$where}.\n\nAny assistance accorded to {$him} will be highly appreciated.{$extra}"],
            'probation_confirmation' => ['CONFIRMATION OF APPOINTMENT',
                "Following the successful completion of your probation period, we are pleased to confirm your appointment as {$where} with effect from {$eff}.\n\nAll other terms and conditions of your employment remain as set out in your contract. We thank you for your contribution so far and look forward to your continued commitment.{$extra}"],
            'promotion' => ['PROMOTION',
                "We are pleased to inform you that you have been promoted to the position of {$where} with effect from {$eff}.\n\nThis promotion recognises your performance and commitment. Your new responsibilities and any revised terms will be communicated to you by Human Resources. Congratulations.{$extra}"],
            'transfer' => ['TRANSFER',
                "This is to notify you that you have been transferred to ".($e->branch?->name ? "our {$e->branch->name} branch" : 'a new station').($dept ? ", {$dept} department," : '')." with effect from {$eff}, in your capacity as {$position}.\n\nPlease ensure a proper handover of your current duties before reporting. All other terms of your employment remain unchanged.{$extra}"],
            'warning' => ['WARNING LETTER',
                "This letter serves as a formal written warning regarding your conduct/performance as detailed below.{$extra}\n\nYou are required to improve with immediate effect. Any recurrence may lead to further disciplinary action in line with the Company's disciplinary policy and the Employment Act, 2007. You may respond in writing within seven (7) days of receipt of this letter."],
            'certificate_of_service' => ['CERTIFICATE OF SERVICE',
                "This is to certify that {$title} {$name}{$id} was employed by {$company} from {$joined}".($e->exitRecord?->exit_date ? ' to '.$e->exitRecord->exit_date->format('jS F Y') : '').". During this period {$he} served as {$where}.\n\nThis certificate is issued in accordance with Section 51 of the Employment Act, 2007. We wish {$him} well in future endeavours.{$extra}"],
            'no_objection' => ['NO OBJECTION',
                "This is to confirm that {$title} {$name}{$id} is employed by {$company} as {$where}. The Company has no objection to the request described below.{$extra}"],
            default => ['LETTER', trim((string) $notes)],
        };
    }

    private function cap(string $s): string
    {
        return ucfirst($s);
    }

    private function nextReference(int $companyId): string
    {
        $prefix = 'HR/LTR/'.now()->format('Y').'/';
        $last = EmployeeLetter::withoutGlobalScopes()->where('company_id', $companyId)->where('reference', 'like', $prefix.'%')->orderByDesc('id')->value('reference');

        return $prefix.str_pad((string) ($last ? ((int) substr($last, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }
}
