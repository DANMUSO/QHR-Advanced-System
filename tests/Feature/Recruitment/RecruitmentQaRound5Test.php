<?php

namespace Tests\Feature\Recruitment;

use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Company;
use App\Models\Department;
use App\Models\Position;
use App\Models\User;
use App\Modules\Recruitment\Dashboard\RequisitionApprovals;
use App\Modules\Recruitment\Models\Applicant;
use App\Modules\Recruitment\Models\Interview;
use App\Modules\Recruitment\Models\Offer;
use App\Modules\Recruitment\Models\Posting;
use App\Modules\Recruitment\Models\Requisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** QA round 5: requisition self-approval, offer "send now", interview → stage + score, offer eligibility, early completion. */
class RecruitmentQaRound5Test extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(EnsureAccountIsSecure::class);
        Artisan::call('hr:sync-permissions');
        Notification::fake();
        $this->company = Company::create(['name' => 'Acme Ltd', 'code' => 'ACM', 'is_active' => true]);
        $this->hr = $this->user(['hr-admin'], 'Hannah');
    }

    private function user(array $roles, string $name): User
    {
        $user = User::create([
            'company_id' => $this->company->id, 'name' => $name, 'email' => uniqid(strtolower($name)).'@t.test',
            'password' => 'Password@123', 'is_active' => true, 'activated_at' => now(),
        ]);
        $user->assignRole($roles);

        return $user->fresh();
    }

    private function requisition(User $by, int $vacancies = 1): Requisition
    {
        $dept = Department::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'name' => 'Ops '.uniqid()]);
        $position = Position::withoutGlobalScopes()->create(['company_id' => $this->company->id, 'title' => 'Analyst']);
        $id = $this->actingAs($by)->postJson('/api/recruitment/requisitions', ['position_id' => $position->id, 'department_id' => $dept->id, 'vacancies' => $vacancies, 'type' => 'permanent'])
            ->assertCreated()->json('data.id');

        return Requisition::findOrFail($id);
    }

    private function applicant(string $stage = 'interview', ?int $requisitionId = null): Applicant
    {
        $posting = new Posting(['title' => 'Analyst', 'status' => 'published', 'slug' => uniqid('analyst-'), 'requisition_id' => $requisitionId]);
        $posting->company_id = $this->company->id;
        $posting->save();
        $a = new Applicant(['posting_id' => $posting->id, 'name' => 'Jane Doe', 'email' => uniqid('j').'@cand.test', 'stage' => $stage, 'applied_at' => now()->subWeek()]);
        $a->company_id = $this->company->id;
        $a->save();

        return $a;
    }

    private function offerPayload(Applicant $a, array $extra = []): array
    {
        return ['applicant_id' => $a->id, 'offered_salary' => 100000, 'start_date' => now()->addMonth()->toDateString(), 'expiry_date' => now()->addWeeks(2)->toDateString()] + $extra;
    }

    public function test_requester_cannot_approve_or_reject_own_requisition_even_as_super_admin(): void
    {
        $root = $this->user(['super-admin'], 'Root');
        $req = $this->requisition($root);

        $this->actingAs($root)->postJson("/api/recruitment/requisitions/{$req->id}/approve")->assertForbidden();
        $this->actingAs($root)->postJson("/api/recruitment/requisitions/{$req->id}/reject", ['note' => 'no'])->assertForbidden();
        $this->assertSame('pending_approval', $req->fresh()->status);
        $this->assertFalse(collect($this->actingAs($root)->getJson('/api/recruitment/requisitions')->json('data'))->firstWhere('id', $req->id)['can_decide']);
        $this->assertSame(0, (new RequisitionApprovals)->countFor($root));

        $this->assertSame(1, (new RequisitionApprovals)->countFor($this->hr));
        $this->actingAs($this->hr)->postJson("/api/recruitment/requisitions/{$req->id}/approve")->assertOk();
        $this->assertSame('approved', $req->fresh()->status);
    }

    public function test_offer_marked_sent_on_create_is_sent(): void
    {
        $a = $this->applicant();
        $this->actingAs($this->hr)->postJson('/api/recruitment/offers', $this->offerPayload($a, ['status' => 'sent']))
            ->assertCreated()->assertJsonPath('data.status', 'sent');
        $offer = Offer::where('applicant_id', $a->id)->firstOrFail();
        $this->assertNotNull($offer->sent_at);
        $this->assertSame('offer', $a->fresh()->stage);
    }

    public function test_completing_an_interview_moves_the_stage_and_stores_the_score(): void
    {
        $a = $this->applicant('screening');
        $iv = new Interview(['applicant_id' => $a->id, 'interview_type' => 'panel', 'scheduled_at' => now()->subHour(), 'status' => 'scheduled']);
        $iv->company_id = $this->company->id;
        $iv->save();
        $a->update(['stage' => 'screening']);

        $this->actingAs($this->hr)->putJson("/api/recruitment/interviews/{$iv->id}", ['status' => 'completed', 'score' => 82])->assertOk();
        $a->refresh();
        $this->assertSame('interview', $a->stage);
        $this->assertSame(82, (int) $a->score);
        $this->getJson("/api/recruitment/applicants/{$a->id}")->assertOk()
            ->assertJsonPath('data.score', 82)->assertJsonPath('data.interviews.0.score', 82);
    }

    public function test_interview_cannot_be_completed_before_its_scheduled_time(): void
    {
        $a = $this->applicant('screening');
        $id = $this->actingAs($this->hr)->postJson('/api/recruitment/interviews', ['applicant_id' => $a->id, 'interview_type' => 'hr', 'scheduled_at' => now()->addDay()->format('Y-m-d H:i')])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/recruitment/interviews/{$id}", ['status' => 'completed', 'score' => 70])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->assertSame('scheduled', Interview::find($id)->status);
    }

    public function test_offers_only_for_interviewed_applicants_and_never_for_hired(): void
    {
        $this->actingAs($this->hr)->postJson('/api/recruitment/offers', $this->offerPayload($this->applicant('applied')))->assertStatus(422);
        $this->postJson('/api/recruitment/offers', $this->offerPayload($this->applicant('hired')))->assertStatus(422);

        $a = $this->applicant('interview');
        $offer = $this->postJson('/api/recruitment/offers', $this->offerPayload($a, ['status' => 'sent']))->assertCreated()->json('data.id');
        $this->postJson("/api/recruitment/offers/{$offer}/status", ['status' => 'accepted'])->assertOk();
        $this->assertSame('hired', $a->fresh()->stage);
        $this->postJson('/api/recruitment/offers', $this->offerPayload($a))->assertStatus(422);
    }

    public function test_hires_cannot_exceed_vacancies_and_stage_actions_need_reasons(): void
    {
        $other = $this->user(['hr-admin'], 'Henry');
        $req = $this->requisition($other, 1);
        $this->actingAs($this->hr)->postJson("/api/recruitment/requisitions/{$req->id}/approve")->assertOk();
        $first = $this->applicant('offer', $req->id);
        $second = $this->applicant('offer', $req->id);
        Applicant::whereKey($second->id)->update(['posting_id' => $first->posting_id]);

        $this->patchJson("/api/recruitment/applicants/{$first->id}/stage", ['stage' => 'hired'])->assertOk();
        $this->assertSame('filled', $req->fresh()->status);
        $this->patchJson("/api/recruitment/applicants/{$second->id}/stage", ['stage' => 'hired'])->assertStatus(422);

        $this->patchJson("/api/recruitment/applicants/{$second->id}/stage", ['stage' => 'rejected'])->assertStatus(422);
        $this->patchJson("/api/recruitment/applicants/{$second->id}/stage", ['stage' => 'rejected', 'reason' => 'Position filled'])->assertOk();
        $this->assertStringContainsString('Position filled', $second->fresh()->notes);
        $fresh = $this->applicant('applied');
        $this->patchJson("/api/recruitment/applicants/{$fresh->id}/stage", ['stage' => 'offer'])->assertStatus(422);
        $this->patchJson("/api/recruitment/applicants/{$fresh->id}/stage", ['stage' => 'screening'])->assertOk()->assertJsonPath('data.next_stage', 'interview');
    }
}
