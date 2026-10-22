<?php

namespace App\Modules\Compliance\Database;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Compliance\Models\BackgroundCheck;
use App\Modules\Compliance\Models\DataRequest;
use App\Modules\Compliance\Models\MedicalCover;
use App\Modules\Compliance\Models\DisciplinaryCase;
use App\Modules\Compliance\Models\Grievance;
use App\Modules\Compliance\Models\Hazard;
use App\Modules\Compliance\Models\Incident;
use App\Modules\Compliance\Models\WarningLetter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/** Idempotent compliance demo data (a register is only seeded for a company while it is empty). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('comp_data_requests')) {
            return;
        }

        foreach (Company::all() as $company) {
            $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')->orderBy('id')->get();
            if ($employees->count() < 6) {
                continue;
            }
            $n = $employees->count();
            $hrUser = User::where('company_id', $company->id)->whereHas('roles', fn ($q) => $q->where('name', 'hr-admin'))->value('id');
            $year = now()->year;
            $c = $company->id;
            $this->seedVerification($company, $employees);

            if (! DisciplinaryCase::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $cases = [
                    ['Habitual lateness (7 incidents in 30 days)', 'under_investigation', null, 40],
                    ['Unauthorised absence for 3 consecutive days', 'hearing_scheduled', null, 21],
                    ['Misuse of company fuel card', 'closed', 'final_warning', 75],
                    ['Insubordination towards supervisor', 'closed', 'warning', 120],
                    ['Breach of customer data confidentiality policy', 'open', null, 5],
                ];
                foreach ($cases as $i => [$offence, $status, $outcome, $daysAgo]) {
                    $this->make(new DisciplinaryCase, $c, [
                        'case_number' => sprintf('DC-%d-%d%03d', $year, $c, $i + 1),
                        'employee_id' => $employees[($i * 7 + 3) % $n]->id,
                        'offence' => $offence,
                        'description' => 'Reported by the line manager; supporting records attached to the employee file.',
                        'reported_date' => now()->subDays($daysAgo)->toDateString(),
                        'hearing_date' => $status === 'hearing_scheduled' ? now()->addDays(4)->toDateString() : null,
                        'status' => $status,
                        'outcome' => $outcome,
                        'reported_by_user_id' => $hrUser,
                    ]);
                }
            }

            if (! Grievance::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $items = [
                    ['pay', 'Overtime for March not reflected on payslip.', 'resolved', 60],
                    ['working_conditions', 'Air conditioning in the branch office has been faulty for weeks.', 'investigating', 14],
                    ['harassment', 'Repeated inappropriate remarks by a colleague.', 'mediation', 9],
                    ['workplace', 'Unfair allocation of weekend shifts within the team.', 'open', 2],
                ];
                foreach ($items as $i => [$cat, $desc, $status, $daysAgo]) {
                    $this->make(new Grievance, $c, [
                        'reference' => sprintf('GRV-%d-%d%03d', $year, $c, $i + 1),
                        'employee_id' => $employees[($i * 5 + 2) % $n]->id,
                        'category' => $cat,
                        'description' => $desc,
                        'status' => $status,
                        'filed_date' => now()->subDays($daysAgo)->toDateString(),
                        'assigned_to_employee_id' => $employees->first()->id,
                        'resolution' => $status === 'resolved' ? 'Arrears paid in the following payroll run.' : null,
                    ]);
                }
            }

            if (! WarningLetter::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $items = [
                    ['verbal', 'Late reporting on three occasions.', 100],
                    ['written', 'Failure to follow cash handling procedure.', 50],
                    ['final', 'Misuse of company fuel card.', 70],
                ];
                foreach ($items as $i => [$level, $reason, $daysAgo]) {
                    $issued = now()->subDays($daysAgo);
                    $this->make(new WarningLetter, $c, [
                        'employee_id' => $employees[($i * 7 + 3) % $n]->id,
                        'level' => $level,
                        'reason' => $reason,
                        'issued_date' => $issued->toDateString(),
                        'expiry_date' => $issued->copy()->addMonths($level === 'final' ? 12 : 6)->toDateString(),
                        'issued_by_user_id' => $hrUser,
                    ]);
                }
            }

            if (! Hazard::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $items = [
                    ['Exposed electrical cabling near reception', 'hazard', 'high', 'open', 3],
                    ['Fire extinguishers past service date (2nd floor)', 'fire_safety', 'critical', 'in_progress', 10],
                    ['Wet floor at warehouse entrance during rains', 'near_miss', 'medium', 'closed', 35],
                    ['Non-adjustable chairs in the call centre', 'ergonomics', 'low', 'open', 20],
                ];
                foreach ($items as [$title, $cat, $risk, $status, $daysAgo]) {
                    $this->make(new Hazard, $c, [
                        'title' => $title,
                        'category' => $cat,
                        'risk_level' => $risk,
                        'status' => $status,
                        'description' => 'Logged during the monthly OSHA workplace inspection.',
                        'reported_date' => now()->subDays($daysAgo)->toDateString(),
                        'reported_by_user_id' => $hrUser,
                        'action_taken' => $status === 'closed' ? 'Anti-slip mats installed and signage placed.' : null,
                    ]);
                }
            }

            if (! Incident::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $items = [
                    ['Staff slipped on stairs, sprained ankle', 'accident', 'moderate', 'Head office stairwell', 'closed', 45],
                    ['Delivery van minor collision in yard', 'property_damage', 'minor', 'Loading bay', 'investigating', 12],
                    ['Unauthorised person found in server room', 'security', 'serious', 'Server room, 3rd floor', 'open', 4],
                    ['Box fell from top shelf, narrowly missed staff', 'near_miss', 'minor', 'Main store', 'closed', 60],
                ];
                foreach ($items as $i => [$title, $type, $sev, $loc, $status, $daysAgo]) {
                    $this->make(new Incident, $c, [
                        'incident_number' => sprintf('INC-%d-%d%03d', $year, $c, $i + 1),
                        'title' => $title,
                        'type' => $type,
                        'severity' => $sev,
                        'location' => $loc,
                        'status' => $status,
                        'incident_date' => now()->subDays($daysAgo)->setTime(10 + $i, 15),
                        'description' => 'Initial report captured by the area supervisor.',
                        'employee_id' => $type === 'accident' ? $employees[4]->id : null,
                        'reported_by_user_id' => $hrUser,
                        'corrective_action' => $status === 'closed' ? 'Toolbox talk held; handrails inspected.' : null,
                    ]);
                }
            }

            if (! DataRequest::withoutGlobalScopes()->where('company_id', $c)->exists()) {
                $items = [
                    ['access', 'pending', 3, 'Copy of all personal data held, including payroll records.'],
                    ['rectification', 'in_progress', 6, 'Correct date of birth and KRA PIN on file.'],
                    ['erasure', 'completed', 40, 'Former applicant requests deletion of CV and references.'],
                    ['portability', 'rejected', 25, 'Export of performance records in machine-readable format.'],
                ];
                $dueDays = ['access' => 7, 'rectification' => 14, 'erasure' => 14];
                foreach ($items as $i => [$type, $status, $daysAgo, $desc]) {
                    $e = $employees[($i * 9 + 1) % $n];
                    $submitted = now()->subDays($daysAgo);
                    $external = $type === 'erasure';
                    $this->make(new DataRequest, $c, [
                        'reference' => sprintf('DSR-%d-%d%03d', $year, $c, $i + 1),
                        'requester_name' => $external ? 'Former job applicant' : $e->full_name,
                        'requester_email' => $external ? 'applicant@example.com' : $e->email,
                        'employee_id' => $external ? null : $e->id,
                        'type' => $type,
                        'status' => $status,
                        'description' => $desc,
                        'submitted_date' => $submitted->toDateString(),
                        'due_date' => $submitted->copy()->addDays($dueDays[$type] ?? 30)->toDateString(),
                        'completed_at' => $status === 'completed' ? $submitted->copy()->addDays(9) : null,
                        'response_notes' => $status === 'rejected' ? 'Request outside scope; records shared in PDF instead.' : null,
                    ]);
                }
            }
        }
    }

    /** CRB / good-conduct certificates and medical cover records. */
    private function seedVerification(Company $company, $employees): void
    {
        $c = $company->id;
        $n = $employees->count();

        if (Schema::hasTable('comp_background_checks') && ! BackgroundCheck::withoutGlobalScopes()->where('company_id', $c)->exists()) {
            foreach ($employees->take(12)->values() as $i => $e) {
                $issued = now()->subMonths(2 + ($i * 3) % 30);
                $type = ['good_conduct', 'crb_check', 'police_clearance'][$i % 3];
                $this->make(new BackgroundCheck, $c, [
                    'employee_id' => $e->id,
                    'type' => $type,
                    'reference_number' => sprintf('%s/%d/%05d', ['good_conduct' => 'DCI-PCC', 'crb_check' => 'CRB', 'police_clearance' => 'NPS'][$type], $issued->year, 1000 + $e->id * 7),
                    'issue_date' => $i === 11 ? null : $issued->toDateString(),
                    'expiry_date' => $i === 11 ? null : $issued->copy()->addYear()->toDateString(),
                    'status' => $i === 11 ? 'pending' : ($i === 7 ? 'flagged' : 'clear'),
                    'notes' => $i === 7 ? 'Listed with a CRB for a defaulted loan; payment plan submitted.' : null,
                ]);
            }
        }

        if (Schema::hasTable('comp_medical_covers') && ! MedicalCover::withoutGlobalScopes()->where('company_id', $c)->exists()) {
            $schemes = [['AAR Insurance - Staff Gold', 1000000], ['Jubilee Health - Corporate Silver', 500000], ['CIC Group Medical - Standard', 300000]];
            foreach ($employees->take(min(20, $n))->values() as $i => $e) {
                [$scheme, $limit] = $schemes[$i % 3];
                $start = now()->startOfYear();
                $this->make(new MedicalCover, $c, [
                    'employee_id' => $e->id,
                    'scheme_name' => $scheme,
                    'member_number' => sprintf('MED-%d-%05d', $c, $e->id),
                    'cover_limit' => $limit,
                    'issue_date' => $start->toDateString(),
                    'expiry_date' => $i === 5 ? now()->subDays(10)->toDateString() : $start->copy()->endOfYear()->toDateString(),
                    'status' => 'active',
                ]);
            }
        }
    }

    private function make($model, int $companyId, array $attributes): void
    {
        $model->forceFill(['company_id' => $companyId] + $attributes)->save();
    }
}
