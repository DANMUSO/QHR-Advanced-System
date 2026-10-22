<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Models\MedicalClaimEvent;
use App\Modules\Claims\Models\MedicalClaimItem;
use App\Modules\Engagement\Models\EngagementComment;
use App\Modules\Engagement\Models\EngagementReply;
use App\Modules\Payroll\Models\PayrollSalaryAdvance;
use App\Modules\Performance\Models\Appraisal;
use App\Modules\Performance\Models\Cycle;
use App\Modules\Performance\Models\Enrollment;
use App\Modules\Performance\Models\Course;
use App\Modules\Performance\Models\Goal;
use App\Modules\Performance\Models\Kpi;
use App\Modules\Performance\Models\Recognition;
use App\Modules\Probation\Models\ProbationReview;
use Illuminate\Database\Seeder;

class DemoAccountsSeeder extends Seeder
{
    private array $emails = [
        'hr@hr.test',
        'finance@hr.test',
        'payroll@hr.test',
        'supervisor@hr.test',
        'employee@hr.test',
    ];

    public function run(): void
    {
        foreach ($this->emails as $email) {
            $user = User::where('email', $email)->first();
            if (!$user) continue;

            $emp = Employee::withoutGlobalScopes()->where('user_id', $user->id)->first();
            if (!$emp) continue;

            $this->seedGoals($emp);
            $this->seedAppraisals($emp);
            $this->seedTraining($emp);
            $this->seedClaims($emp);
            $this->seedSuggestions($emp, $user);
            $this->seedSalaryAdvance($emp);
            $this->seedProbation($emp);
            $this->seedRecognition($emp);
        }
    }

    private function seedGoals(Employee $emp): void
    {
        $cycle = Cycle::withoutGlobalScopes()
            ->where('company_id', $emp->company_id)
            ->where('status', 'active')
            ->first();

        if (!$cycle) return;

        $supervisor = $emp->primary_supervisor_id
            ? Employee::withoutGlobalScopes()->find($emp->primary_supervisor_id)
            : null;

        $kpis = Kpi::withoutGlobalScopes()
            ->where('company_id', $emp->company_id)
            ->take(3)
            ->get();

        if ($kpis->isEmpty()) return;

        $goalDefs = [
            ['status' => 'approved',     'achieved' => null],
            ['status' => 'in_progress',  'achieved' => null],
            ['status' => 'submitted',    'achieved' => null],
        ];

        foreach ($kpis as $i => $kpi) {
            if (Goal::withoutGlobalScopes()
                ->where('cycle_id', $cycle->id)
                ->where('employee_id', $emp->id)
                ->where('kpi_id', $kpi->id)
                ->exists()) continue;

            $def      = $goalDefs[$i % count($goalDefs)];
            $target   = $kpi->target ?? 100;
            $achieved = $def['status'] === 'approved' ? round($target * (rand(75, 115) / 100), 2) : null;

            Goal::create([
                'company_id'      => $emp->company_id,
                'cycle_id'        => $cycle->id,
                'kpi_id'          => $kpi->id,
                'employee_id'     => $emp->id,
                'assigned_by'     => $supervisor?->id ?? $emp->id,
                'approver_id'     => $supervisor?->id ?? $emp->id,
                'target'          => $target,
                'due_date'        => $cycle->end_date,
                'status'          => $def['status'],
                'achieved'        => $achieved,
                'achievement_pct' => $achieved ? round(($achieved / $target) * 100, 2) : 0,
                'score'           => $achieved ? min(100, round(($achieved / $target) * 100, 2)) : 0,
            ]);
        }
    }

    private function seedAppraisals(Employee $emp): void
    {
        // Closed cycle appraisal
        $closedCycle = Cycle::withoutGlobalScopes()
            ->where('company_id', $emp->company_id)
            ->where('status', 'closed')
            ->first();

        if ($closedCycle && !Appraisal::withoutGlobalScopes()
            ->where('cycle_id', $closedCycle->id)
            ->where('employee_id', $emp->id)
            ->exists()) {

            $supervisor = $emp->primary_supervisor_id
                ? Employee::withoutGlobalScopes()->find($emp->primary_supervisor_id)
                : null;

            $kpiScore  = rand(65, 95);
            $compScore = rand(60, 90);
            $final     = round($kpiScore * 0.7 + $compScore * 0.3, 2);
            $band      = $final >= 90 ? 'Outstanding' : ($final >= 75 ? 'Exceeds Expectations' : ($final >= 60 ? 'Meets Expectations' : 'Needs Improvement'));

            Appraisal::create([
                'company_id'         => $emp->company_id,
                'cycle_id'           => $closedCycle->id,
                'employee_id'        => $emp->id,
                'supervisor_id'      => $supervisor?->id,
                'kpi_score'          => $kpiScore,
                'competency_score'   => $compScore,
                'kpi_weight'         => 70,
                'competency_weight'  => 30,
                'final_score'        => $final,
                'rating_band'        => $band,
                'status'             => 'completed',
                'finalized_at'       => now()->subMonths(rand(2, 6)),
                'supervisor_comment' => 'Good performance overall. Demonstrated commitment and delivered results.',
                'employee_comment'   => 'I worked hard to meet all my targets this year and supported my team.',
            ]);
        }

        // Active cycle appraisal (self-review stage)
        $activeCycle = Cycle::withoutGlobalScopes()
            ->where('company_id', $emp->company_id)
            ->where('status', 'active')
            ->first();

        if ($activeCycle && !Appraisal::withoutGlobalScopes()
            ->where('cycle_id', $activeCycle->id)
            ->where('employee_id', $emp->id)
            ->exists()) {

            $supervisor = $emp->primary_supervisor_id
                ? Employee::withoutGlobalScopes()->find($emp->primary_supervisor_id)
                : null;

            Appraisal::create([
                'company_id'        => $emp->company_id,
                'cycle_id'          => $activeCycle->id,
                'employee_id'       => $emp->id,
                'supervisor_id'     => $supervisor?->id,
                'kpi_weight'        => 70,
                'competency_weight' => 30,
                'status'            => 'self_review',
                'employee_comment'  => 'I have been working on improving my performance this year.',
            ]);
        }
    }

    private function seedTraining(Employee $emp): void
    {
        $courses = Course::withoutGlobalScopes()
            ->where('company_id', $emp->company_id)
            ->get();

        if ($courses->isEmpty()) return;

        $enrollDefs = [
            ['status' => 'completed',   'progress' => 100],
            ['status' => 'in_progress', 'progress' => rand(30, 70)],
            ['status' => 'enrolled',    'progress' => 0],
        ];

        foreach ($courses->take(3) as $i => $course) {
            if (Enrollment::withoutGlobalScopes()
                ->where('employee_id', $emp->id)
                ->where('course_id', $course->id)
                ->exists()) continue;

            $def       = $enrollDefs[$i % count($enrollDefs)];
            $startDate = now()->subDays(rand(10, 90));

            Enrollment::create([
                'course_id'    => $course->id,
                'employee_id'  => $emp->id,
                'company_id'   => $emp->company_id,
                'status'       => $def['status'],
                'start_date'   => $startDate->toDateString(),
                'end_date'     => $def['status'] === 'completed'
                    ? $startDate->copy()->addDays(rand(5, 20))->toDateString() : null,
                'progress'     => $def['progress'],
                'completed_at' => $def['status'] === 'completed'
                    ? $startDate->copy()->addDays(rand(5, 20))->toDateString() : null,
            ]);
        }
    }

    private function seedClaims(Employee $emp): void
    {
        if (MedicalClaim::withoutGlobalScopes()->where('employee_id', $emp->id)->exists()) return;

        $ref = 'CLM-' . str_pad((string)(MedicalClaim::withoutGlobalScopes()->count() + 1), 5, '0', STR_PAD_LEFT);

        $claimDefs = [
            ['status' => MedicalClaim::PAID,       'provider' => 'Nairobi Hospital',    'total' => 8500,  'approved' => 8000],
            ['status' => MedicalClaim::HR_APPROVED, 'provider' => 'Aga Khan Hospital',  'total' => 12000, 'approved' => 11500],
            ['status' => MedicalClaim::SUBMITTED,   'provider' => 'MP Shah Hospital',   'total' => 4200,  'approved' => null],
        ];

        foreach ($claimDefs as $i => $def) {
            $claimDate = now()->subDays(rand(10, 120));
            $ref       = 'CLM-' . str_pad((string)(MedicalClaim::withoutGlobalScopes()->count() + 1), 5, '0', STR_PAD_LEFT);

            $claim = MedicalClaim::create([
                'company_id'      => $emp->company_id,
                'reference'       => $ref,
                'employee_id'     => $emp->id,
                'claimant_type'   => $i === 0 ? 'spouse' : 'self',
                'claimant_name'   => $emp->full_name,
                'claim_date'      => $claimDate->toDateString(),
                'provider_name'   => $def['provider'],
                'total_amount'    => $def['total'],
                'approved_amount' => $def['approved'],
                'status'          => $def['status'],
                'submitted_at'    => $claimDate->copy()->addDay(),
                'hr_decided_at'   => in_array($def['status'], [MedicalClaim::HR_APPROVED, MedicalClaim::PAID])
                    ? $claimDate->copy()->addDays(3) : null,
                'paid_on'         => $def['status'] === MedicalClaim::PAID
                    ? $claimDate->copy()->addDays(10)->toDateString() : null,
                'payment_method'  => $def['status'] === MedicalClaim::PAID ? 'mpesa' : null,
            ]);

            MedicalClaimItem::create([
                'medical_claim_id' => $claim->id,
                'category'         => collect(['consultation', 'pharmacy', 'laboratory', 'radiology'])->random(),
                'description'      => 'Medical service',
                'amount'           => $def['total'],
            ]);

            MedicalClaimEvent::create([
                'medical_claim_id' => $claim->id,
                'action'           => 'submitted',
                'from_status'      => null,
                'to_status'        => MedicalClaim::SUBMITTED,
                'created_at'       => $claimDate->copy()->addDay(),
            ]);

            if ($def['status'] === MedicalClaim::PAID) {
                MedicalClaimEvent::create([
                    'medical_claim_id' => $claim->id,
                    'action'           => 'hr_approved',
                    'from_status'      => MedicalClaim::SUBMITTED,
                    'to_status'        => MedicalClaim::HR_APPROVED,
                    'created_at'       => $claimDate->copy()->addDays(3),
                ]);
                MedicalClaimEvent::create([
                    'medical_claim_id' => $claim->id,
                    'action'           => 'paid',
                    'from_status'      => MedicalClaim::HR_APPROVED,
                    'to_status'        => MedicalClaim::PAID,
                    'created_at'       => $claimDate->copy()->addDays(10),
                ]);
            }
        }
    }

    private function seedSuggestions(Employee $emp, User $user): void
    {
        if (EngagementComment::withoutGlobalScopes()->where('employee_id', $emp->id)->exists()) return;

        $hrUser = User::whereHas('roles', fn($q) => $q->where('name', 'hr-admin'))
            ->where('company_id', $emp->company_id)
            ->first();

        $suggestions = [
            ['satisfaction', 'It would be great to have a monthly team lunch to improve collaboration and morale.', false, true],
            ['tools',        'The new HR portal is very easy to use. The leave application process is much faster now.', false, false],
            ['environment',  'The office printer on the 2nd floor has been broken for two weeks. Please arrange a repair.', true, false],
        ];

        foreach ($suggestions as [$category, $body, $anon, $hasReply]) {
            $comment = EngagementComment::create([
                'company_id'  => $emp->company_id,
                'employee_id' => $anon ? null : $emp->id,
                'category'    => $category,
                'body'        => $body,
                'anonymous'   => $anon,
                'status'      => $hasReply ? 'replied' : 'pending',
                'created_at'  => now()->subDays(rand(5, 60)),
            ]);

            if ($hasReply && $hrUser) {
                EngagementReply::create([
                    'company_id' => $emp->company_id,
                    'comment_id' => $comment->id,
                    'user_id'    => $hrUser->id,
                    'body'       => 'Thank you for your feedback. We have noted this and will action it shortly.',
                    'seen'       => true,
                    'created_at' => $comment->created_at->copy()->addDays(2),
                ]);
            }
        }
    }

    private function seedSalaryAdvance(Employee $emp): void
    {
        if (PayrollSalaryAdvance::withoutGlobalScopes()->where('employee_id', $emp->id)->exists()) return;

        $hrUser = User::whereHas('roles', fn($q) => $q->whereIn('name', ['hr-admin', 'finance']))
            ->where('company_id', $emp->company_id)
            ->value('id');

        $advances = [
            ['amount' => 30000, 'reason' => 'School fees for children — term 3 payment due.', 'status' => 'approved'],
            ['amount' => 15000, 'reason' => 'Medical emergency — hospital deposit required.',  'status' => 'pending'],
        ];

        foreach ($advances as $adv) {
            PayrollSalaryAdvance::create([
                'company_id'      => $emp->company_id,
                'employee_id'     => $emp->id,
                'amount'          => $adv['amount'],
                'reason'          => $adv['reason'],
                'status'          => $adv['status'],
                'decided_by'      => $adv['status'] === 'approved' ? $hrUser : null,
                'decided_at'      => $adv['status'] === 'approved' ? now()->subDays(rand(2, 10)) : null,
                'recovery_period' => $adv['status'] === 'approved'
                    ? now()->addMonth()->startOfMonth()->toDateString() : null,
            ]);
        }
    }

    private function seedProbation(Employee $emp): void
    {
        if (ProbationReview::withoutGlobalScopes()->where('employee_id', $emp->id)->exists()) return;

        // Only seed if employee joined recently enough to have been on probation
        $joined = $emp->date_of_joining;
        if (!$joined) return;

        ProbationReview::create([
            'company_id'          => $emp->company_id,
            'employee_id'         => $emp->id,
            'probation_end_date'  => $joined->copy()->addMonths($emp->probation_months ?? 6)->toDateString(),
            'status'              => 'confirmed',
            'decision'            => 'confirm',
            'primary_comment'     => 'Employee has demonstrated excellent performance and a positive attitude throughout the probation period. Highly recommend confirmation.',
            'primary_reviewed_at' => $joined->copy()->addMonths(($emp->probation_months ?? 6) - 1),
            'hr_comment'          => 'Confirmed as per supervisor recommendation. Welcome to the permanent team!',
            'hr_reviewed_at'      => $joined->copy()->addMonths($emp->probation_months ?? 6)->subDays(5),
        ]);
    }

    private function seedRecognition(Employee $emp): void
    {
        if (Recognition::withoutGlobalScopes()->where('employee_id', $emp->id)->exists()) return;

        $hrUser = User::whereHas('roles', fn($q) => $q->whereIn('name', ['hr-admin', 'supervisor']))
            ->where('company_id', $emp->company_id)
            ->value('id');

        Recognition::create([
            'company_id'         => $emp->company_id,
            'employee_id'        => $emp->id,
            'awarded_by_user_id' => $hrUser,
            'category'           => collect(['excellence', 'teamwork', 'innovation', 'customer_focus'])->random(),
            'message'            => collect([
                'Outstanding performance this quarter — consistently exceeded all targets and supported the team.',
                'Exceptional dedication during the year-end audit. Your commitment made a real difference.',
                'Recognised for going above and beyond to deliver excellent customer service.',
                'Thank you for your innovative approach that saved the team significant time this month.',
            ])->random(),
            'created_at' => now()->subDays(rand(10, 90)),
        ]);
    }
}
