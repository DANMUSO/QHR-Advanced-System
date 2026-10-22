<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Modules\Claims\Models\ClaimApprover;
use App\Modules\Claims\Models\MedicalClaim;
use App\Modules\Claims\Models\MedicalClaimEvent;
use App\Modules\Claims\Models\MedicalClaimItem;
use Illuminate\Database\Seeder;

class ClaimsDataSeeder extends Seeder
{
    public function run(): void
    {
        Company::all()->each(function (Company $company) {
            $employees = Employee::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('status', 'active')
                ->get();

            $hrEmployee = $employees->first(fn ($e) => str_contains(strtolower($e->position?->title ?? ''), 'hr'));
            $finEmployee = $employees->first(fn ($e) => str_contains(strtolower($e->position?->title ?? ''), 'finance'));

            // Set up approvers
            ClaimApprover::withoutGlobalScopes()->updateOrCreate(
                ['company_id' => $company->id],
                [
                    'medical_approver_id' => $hrEmployee?->id ?? $employees->first()?->id,
                    'finance_approver_id' => $finEmployee?->id ?? $employees->first()?->id,
                ]
            );

            $categories = array_keys(MedicalClaim::CATEGORIES);
            $providers  = ['Nairobi Hospital', 'Aga Khan Hospital', 'MP Shah Hospital', 'Kenyatta National Hospital', 'Gertrude\'s Hospital', 'AAR Clinic', 'Coptic Hospital'];
            $statuses   = [
                MedicalClaim::SUBMITTED,
                MedicalClaim::SUBMITTED,
                MedicalClaim::HR_APPROVED,
                MedicalClaim::HR_APPROVED,
                MedicalClaim::PAID,
                MedicalClaim::PAID,
                MedicalClaim::PAID,
                MedicalClaim::HR_REJECTED,
            ];

            $ref = 1000 + MedicalClaim::withoutGlobalScopes()->count();

            foreach ($employees->take(20) as $employee) {
                $numClaims = rand(1, 4);
                for ($i = 0; $i < $numClaims; $i++) {
                    $status    = $statuses[array_rand($statuses)];
                    $claimDate = now()->subDays(rand(5, 180));
                    $total     = rand(500, 15000);
                    $approved  = in_array($status, [MedicalClaim::HR_APPROVED, MedicalClaim::PAID])
                        ? round($total * (rand(80, 100) / 100))
                        : null;

                    $claim = MedicalClaim::withoutGlobalScopes()->create([
                        'company_id'     => $company->id,
                        'reference'      => 'CLM-' . str_pad((string) $ref++, 5, '0', STR_PAD_LEFT),
                        'employee_id'    => $employee->id,
                        'claimant_type'  => collect(['self', 'self', 'self', 'spouse', 'child'])->random(),
                        'claimant_name'  => $employee->full_name,
                        'claim_date'     => $claimDate->toDateString(),
                        'provider_name'  => $providers[array_rand($providers)],
                        'total_amount'   => $total,
                        'approved_amount'=> $approved,
                        'status'         => $status,
                        'submitted_at'   => $claimDate->copy()->addDays(1),
                        'hr_decided_at'  => in_array($status, [MedicalClaim::HR_APPROVED, MedicalClaim::HR_REJECTED, MedicalClaim::PAID])
                            ? $claimDate->copy()->addDays(rand(2, 7)) : null,
                        'paid_on'        => $status === MedicalClaim::PAID
                            ? $claimDate->copy()->addDays(rand(7, 21))->toDateString() : null,
                        'payment_method' => $status === MedicalClaim::PAID ? collect(['mpesa', 'bank'])->random() : null,
                    ]);

                    // 1-3 line items
                    $remaining = $total;
                    $numItems  = rand(1, 3);
                    for ($j = 0; $j < $numItems; $j++) {
                        $amount = $j === $numItems - 1 ? $remaining : rand(200, (int) ($remaining * 0.7));
                        $remaining -= $amount;
                        MedicalClaimItem::create([
                            'medical_claim_id' => $claim->id,
                            'category'         => $categories[array_rand($categories)],
                            'description'      => 'Medical service',
                            'amount'           => $amount,
                        ]);
                    }

                    // Audit event
                    MedicalClaimEvent::create([
                        'medical_claim_id' => $claim->id,
                        'action'           => 'submitted',
                        'from_status'      => null,
                        'to_status'        => MedicalClaim::SUBMITTED,
                        'created_at'       => $claimDate->copy()->addDays(1),
                    ]);

                    if ($status === MedicalClaim::PAID) {
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
        });
    }
}
