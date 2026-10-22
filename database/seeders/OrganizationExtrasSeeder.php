<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Modules\Organization\Models\Integration;
use App\Modules\Organization\Models\Reminder;
use App\Modules\Organization\Models\SupportReply;
use App\Modules\Organization\Models\SupportTicket;
use App\Modules\Organization\Models\Workflow;
use Illuminate\Database\Seeder;

class OrganizationExtrasSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $this->seedReminders($company);
            $this->seedWorkflows($company);
            $this->seedIntegrations($company);
            $this->seedSupportTickets($company);
        });
    }

    private function seedReminders(Company $company): void
    {
        $reminders = [
            ['Contract Expiry Alert',    'contract_expiry',   30, 'email',  'hr'],
            ['Probation End Reminder',   'probation_end',     14, 'email',  'hr'],
            ['Document Expiry Warning',  'document_expiry',   60, 'in_app', 'hr'],
            ['Work Anniversary',         'work_anniversary',  7,  'in_app', 'employee'],
            ['Birthday Greeting',        'birthday',          1,  'in_app', 'employee'],
            ['Leave Balance Reset',      'leave_balance_reset',14,'email',  'hr'],
        ];

        foreach ($reminders as [$name, $event, $days, $channel, $recipients]) {
            Reminder::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'event'       => $event,
                    'days_before' => $days,
                    'channel'     => $channel,
                    'recipients'  => $recipients,
                    'is_active'   => true,
                ]
            );
        }
    }

    private function seedWorkflows(Company $company): void
    {
        $workflows = [
            ['Leave Approval Workflow',   'leave_request',  'Routes leave requests to supervisor then HR.'],
            ['Expense Approval Workflow', 'expense_claim',  'Routes expense claims to line manager then finance.'],
            ['Payroll Approval Workflow', 'payroll_run',    'Payroll run requires finance and HR sign-off.'],
            ['Employee Exit Workflow',    'employee_exit',  'Manages clearance checklist and final settlement.'],
            ['Probation Review Workflow', 'probation_end',  'Triggers supervisor review 2 weeks before probation end.'],
        ];

        foreach ($workflows as [$name, $trigger, $description]) {
            Workflow::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'trigger'     => $trigger,
                    'description' => $description,
                    'steps'       => [
                        ['step' => 1, 'action' => 'notify_supervisor'],
                        ['step' => 2, 'action' => 'notify_hr'],
                    ],
                    'is_active'   => true,
                ]
            );
        }
    }

    private function seedIntegrations(Company $company): void
    {
        $keys = ['mpesa', 'kcb', 'equity', 'google', 'slack', 'zoom'];

        foreach ($keys as $key) {
            Integration::withoutGlobalScopes()->firstOrCreate(
                ['company_id' => $company->id, 'key' => $key],
                ['is_enabled' => false, 'config' => null]
            );
        }
    }

    private function seedSupportTickets(Company $company): void
    {
        $user = User::withoutGlobalScopes()->where('company_id', $company->id)->first();
        if (!$user) return;

        $adminUser = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['hr-admin', 'super-admin']))
            ->first() ?? $user;

        $tickets = [
            ['Cannot access payslips',         'technical', 'I am unable to view my payslips for the last 3 months.',          'open'],
            ['Leave balance incorrect',         'hr',        'My annual leave balance shows 5 days but I should have 12.',      'resolved'],
            ['Password reset not working',      'technical', 'I requested a password reset but did not receive the email.',     'open'],
            ['Update bank account details',     'payroll',   'I changed my bank account and need to update payment details.',   'in_progress'],
            ['Maternity leave policy query',    'hr',        'I would like to understand the maternity leave policy.',          'resolved'],
            ['Expense claim rejected in error', 'finance',   'My expense claim was rejected but all receipts were attached.',   'open'],
        ];

        $counter = SupportTicket::withoutGlobalScopes()->max(
            \DB::raw("CAST(SUBSTRING(ticket_number, 5) AS UNSIGNED)")
        ) + 1;

        foreach ($tickets as [$subject, $category, $description, $status]) {
            if (SupportTicket::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('subject', $subject)
                ->exists()) {
                continue;
            }

            $ticket = SupportTicket::create([
                'company_id'    => $company->id,
                'user_id'       => $user->id,
                'ticket_number' => 'TKT-' . str_pad((string) $counter++, 5, '0', STR_PAD_LEFT),
                'subject'       => $subject,
                'category'      => $category,
                'priority'      => collect(['low', 'medium', 'high'])->random(),
                'description'   => $description,
                'status'        => $status,
                'resolved_at'   => $status === 'resolved' ? now()->subDays(rand(1, 10)) : null,
                'created_at'    => now()->subDays(rand(1, 30)),
            ]);

            if ($status === 'resolved') {
                SupportReply::create([
                    'ticket_id'  => $ticket->id,
                    'user_id'    => $adminUser->id,
                    'body'       => collect([
                        'Thank you for reaching out. This has been resolved. Please check and confirm.',
                        'We have updated your records. The issue should now be resolved.',
                        'This has been escalated to the relevant team and resolved.',
                    ])->random(),
                    'created_at' => $ticket->created_at->copy()->addDays(rand(1, 3)),
                ]);
            }
        }
    }
}
