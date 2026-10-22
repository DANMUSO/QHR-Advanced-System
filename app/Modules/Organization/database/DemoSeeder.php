<?php

namespace App\Modules\Organization\Database;

use App\Models\Company;
use App\Models\ContactPerson;
use App\Models\Holiday;
use App\Models\User;
use App\Modules\Organization\Models\Backup;
use App\Modules\Organization\Models\DataImport;
use App\Modules\Organization\Models\Integration;
use App\Modules\Organization\Models\Reminder;
use App\Modules\Organization\Models\SupportReply;
use App\Modules\Organization\Models\SupportTicket;
use App\Modules\Organization\Models\Webhook;
use App\Modules\Organization\Models\Workflow;
use App\Modules\Organization\Services\BackupService;
use App\Modules\Organization\Support\SettingsSchema;
use Illuminate\Database\Seeder;

/**
 * Idempotent demo data for the Organization module, for every company:
 * settings defaults, contact persons, approval workflows, reminders, integrations, a webhook,
 * import history, a real data backup and help-desk tickets with replies.
 *
 *   php artisan db:seed --class="App\Modules\Organization\Database\DemoSeeder" --force
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::role('super-admin')->orderBy('id')->first();

        foreach (Company::orderBy('id')->get() as $i => $company) {
            $hr = User::role('hr-admin')->where('company_id', $company->id)->orderBy('id')->first()
                ?? User::where('company_id', $company->id)->orderBy('id')->first();

            $this->settings($company, $hr);
            $this->contacts($company, $hr);
            $this->holidays($company);
            $this->workflows($company);
            $this->reminders($company);
            $this->integrations($company, $i);
            $this->webhook($company);
            $this->imports($company, $hr);
            $this->tickets($company, $admin, $i);
            $this->backup($company, $hr);
        }

        $this->command?->info('Organization demo data seeded.');
    }

    private function settings(Company $company, ?User $hr): void
    {
        $defaults = SettingsSchema::defaults();
        $settings = $company->settings ?? [];
        foreach (['overtime_multiplier_normal', 'overtime_multiplier_holiday', 'pay_day', 'late_grace_minutes'] as $key) {
            $settings[$key] ??= $defaults[$key];
        }
        $settings['late_grace_minutes'] = $settings['late_grace_minutes'] ?: 10;
        $settings['hr_contact_name'] ??= $hr?->name;
        $settings['hr_contact_email'] ??= $hr?->email;
        $settings['hr_contact_phone'] ??= $hr?->employee?->phone ?? '+254700000000';
        $settings['timezone'] ??= 'Africa/Nairobi';
        $settings['date_format'] ??= 'd/m/Y';
        $settings['fy_start'] ??= '01-07';
        $company->settings = $settings;
        $company->save();
    }

    private function contacts(Company $company, ?User $hr): void
    {
        $domain = strtolower($company->code ?: 'company').'.co.ke';
        $rows = [
            ['name' => $hr?->name ?? 'HR Office', 'email' => $hr?->email ?? "hr@{$domain}", 'phone' => '+254711000101', 'role' => 'HR contact'],
            ['name' => 'Finance Office', 'email' => "finance@{$domain}", 'phone' => '+254711000102', 'role' => 'Claims payment'],
            ['name' => 'ICT Helpdesk', 'email' => "ict@{$domain}", 'phone' => '+254711000103', 'role' => 'System support'],
        ];
        foreach ($rows as $row) {
            ContactPerson::withoutGlobalScope('company')->updateOrCreate(['company_id' => $company->id, 'role' => $row['role']], $row);
        }
    }

    private function holidays(Company $company): void
    {
        Holiday::updateOrCreate(
            ['company_id' => $company->id, 'name' => $company->name.' Founders Day'],
            ['date' => '2026-03-01', 'recurring' => true],
        );
    }

    private function workflows(Company $company): void
    {
        $flows = [
            ['Standard leave approval', 'leave_request', 'Supervisor approves, HR confirms balance.', [['type' => 'supervisor'], ['type' => 'role', 'role' => 'hr-admin']]],
            ['Medical claim approval', 'medical_claim', 'HR verifies receipts, Finance pays.', [['type' => 'role', 'role' => 'hr-admin'], ['type' => 'role', 'role' => 'finance']]],
            ['Monthly payroll sign-off', 'payroll_run', 'Payroll prepares, Finance approves before bank file release.', [['type' => 'role', 'role' => 'payroll-officer'], ['type' => 'role', 'role' => 'finance']]],
            ['Employee exit clearance', 'employee_exit', 'Head of department clears, HR closes the file.', [['type' => 'hod'], ['type' => 'role', 'role' => 'hr-admin']]],
            ['Probation confirmation', 'probation_end', 'Supervisor reviews, HR confirms or extends.', [['type' => 'supervisor'], ['type' => 'role', 'role' => 'hr-admin']]],
            ['KPI review sign-off', 'kpi_review', 'Supervisor scores, secondary supervisor moderates.', [['type' => 'supervisor'], ['type' => 'secondary_supervisor']]],
        ];
        foreach ($flows as [$name, $trigger, $desc, $steps]) {
            $steps = array_map(fn ($s) => $s + ['role' => null, 'label' => ($s['type'] === 'role' ? str($s['role'])->headline()->toString() : Workflow::STEP_TYPES[$s['type']])], $steps);
            $wf = Workflow::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id, 'trigger' => $trigger, 'name' => $name]);
            $wf->company_id = $company->id;
            $wf->fill(['description' => $desc, 'steps' => $steps, 'is_active' => true])->saveQuietly();
        }
    }

    private function reminders(Company $company): void
    {
        $rules = [
            ['Contract expiry – 30 days notice', 'contract_expiry', 30, 'email', 'hr'],
            ['Contract expiry – final week', 'contract_expiry', 7, 'in_app', 'supervisor'],
            ['Probation review due', 'probation_end', 14, 'email', 'supervisor'],
            ['Document expiry', 'document_expiry', 30, 'in_app', 'hr'],
            ['Birthday wishes', 'birthday', 0, 'in_app', 'employee'],
            ['Upcoming birthdays', 'birthday', 3, 'in_app', 'hr'],
            ['Work anniversary', 'work_anniversary', 0, 'email', 'employee'],
        ];
        foreach ($rules as $n => [$name, $event, $days, $channel, $recipients]) {
            $r = Reminder::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id, 'name' => $name]);
            $r->company_id = $company->id;
            $r->fill(['event' => $event, 'days_before' => $days, 'channel' => $channel, 'recipients' => $recipients, 'is_active' => true]);
            if (! $r->exists) {
                $r->last_run_at = now()->subDay()->setTime(7, 0);
                $r->last_run_count = ($n * 3 + $company->id) % 5;
            }
            $r->save();
        }
    }

    private function integrations(Company $company, int $i): void
    {
        $code = strtoupper($company->code ?: 'HR');
        $configs = [
            'sms_africastalking' => [true, ['username' => strtolower($code).'hr', 'api_key' => 'atsk_demo_'.md5($code), 'sender_id' => substr($code, 0, 11)]],
            'kra_itax' => [true, ['employer_pin' => $company->tax_pin ?: 'P051234567X', 'tax_obligation' => 'PAYE']],
            'bank_eft' => [true, ['bank_name' => $i % 2 ? 'Equity Bank' : 'KCB Bank', 'account_number' => '01100'.str_pad((string) $company->id, 7, '0', STR_PAD_LEFT), 'branch_code' => '01100', 'file_format' => 'CSV']],
            'smtp' => [false, ['host' => 'smtp.office365.com', 'port' => '587', 'username' => 'hr@'.strtolower($code).'.co.ke', 'password' => null, 'encryption' => 'tls', 'from_address' => 'hr@'.strtolower($code).'.co.ke']],
            'biometric_zkteco' => [$i === 0, ['device_ip' => '10.10.'.($company->id).'.50', 'port' => '4370', 'comm_key' => '0', 'sync_interval_minutes' => '15']],
        ];
        foreach ($configs as $key => [$enabled, $config]) {
            $row = Integration::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id, 'key' => $key]);
            $row->company_id = $company->id;
            $row->is_enabled = $enabled;
            $row->config = $config;
            $row->save();
        }
    }

    private function webhook(Company $company): void
    {
        $url = 'https://hooks.example.com/'.strtolower($company->code ?: 'company').'/hr-events';
        $hook = Webhook::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id, 'url' => $url]);
        $hook->company_id = $company->id;
        // Kept inactive so demo activity never calls a real endpoint.
        $hook->fill(['secret' => 'whsec_'.md5($url), 'events' => ['employee.created', 'employee.exited'], 'is_active' => false])->save();
    }

    private function imports(Company $company, ?User $hr): void
    {
        $history = [
            ['employees', 'staff-onboarding-batch-1.csv', 24, 24, 0, 'completed', null, 40],
            ['departments', 'departments.csv', 8, 8, 0, 'completed', null, 41],
            ['employees', 'staff-onboarding-batch-2.csv', 12, 10, 2, 'completed', [['row' => 5, 'message' => 'Unknown department "Logistics".'], ['row' => 11, 'message' => 'The email field must be a valid email address.']], 12],
            ['holidays', 'holidays-2026.csv', 3, 3, 0, 'completed', null, 5],
        ];
        foreach ($history as [$type, $file, $total, $ok, $failed, $status, $errors, $daysAgo]) {
            $row = DataImport::withoutGlobalScope('company')->firstOrNew(['company_id' => $company->id, 'filename' => $file]);
            $row->company_id = $company->id;
            $row->fill(['user_id' => $hr?->id, 'type' => $type, 'total_rows' => $total, 'imported' => $ok, 'failed' => $failed, 'status' => $status, 'errors' => $errors]);
            if (! $row->exists) {
                $row->created_at = $row->updated_at = now()->subDays($daysAgo);
            }
            $row->save();
        }
    }

    private function tickets(Company $company, ?User $admin, int $i): void
    {
        $requesters = User::where('company_id', $company->id)->where('is_active', true)->orderBy('id')->limit(6)->get();
        if ($requesters->isEmpty()) {
            return;
        }
        $tickets = [
            ['Unable to download March payslip', 'payroll', 'high', 'resolved', 'When I click "Download PDF" on my March payslip the page shows an error. Other months work fine.', ['Thanks for reporting. The payslip PDF was regenerated — please try again.', 'Works now, thank you.'], 20],
            ['Request: add "Logistics" department', 'data', 'low', 'closed', 'We are opening a logistics unit next month and need it in the department list for onboarding.', ['Departments can be added by HR under Administration → Structure. I have added it for you this time.'], 15],
            ['Two-factor code not accepted', 'access', 'critical', 'in_progress', 'My authenticator codes are rejected since I changed phones. I cannot sign in to approve leave.', ['We have reset your two-factor authentication. Please sign in and set it up again on the new phone.'], 3],
            ['Leave balance looks wrong after carry forward', 'leave', 'medium', 'open', 'My annual leave balance shows 26 days but I expected 21 + 5 carried forward minus the 4 days I took in January.', [], 1],
            ['Bulk import rejects dates', 'technical', 'medium', 'open', 'The employee import says date_of_birth is invalid for rows formatted 18/04/1992.', [], 0],
        ];
        foreach ($tickets as $n => [$subject, $category, $priority, $status, $body, $replies, $daysAgo]) {
            $requester = $requesters[$n % $requesters->count()];
            $ticket = SupportTicket::firstOrNew(['ticket_number' => sprintf('TCK-D%d-%02d', $company->id, $n + 1)]);
            $ticket->fill([
                'company_id' => $company->id, 'user_id' => $requester->id, 'subject' => $subject, 'category' => $category,
                'priority' => $priority, 'description' => $body, 'status' => $status,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? now()->subDays(max(0, $daysAgo - 2)) : null,
            ]);
            $isNew = ! $ticket->exists;
            if ($isNew) {
                $ticket->created_at = $ticket->updated_at = now()->subDays($daysAgo)->subHours(($n + $i) * 2);
            }
            $ticket->save();

            if ($isNew) {
                foreach ($replies as $k => $text) {
                    $fromSupport = $k % 2 === 0;
                    SupportReply::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $fromSupport ? $admin?->id : $requester->id,
                        'body' => $text,
                        'created_at' => $ticket->created_at->copy()->addHours(3 + $k * 5),
                        'updated_at' => $ticket->created_at->copy()->addHours(3 + $k * 5),
                    ]);
                }
            }
        }
    }

    private function backup(Company $company, ?User $hr): void
    {
        $exists = Backup::withoutGlobalScope('company')->where('company_id', $company->id)->where('status', 'completed')->exists();
        if (! $exists) {
            app(BackupService::class)->create($company, $hr?->id);
        }
    }
}
