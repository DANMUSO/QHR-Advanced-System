<?php

use App\Modules\Probation\Models\ProbationReview;
use App\Modules\Probation\Services\ProbationRouting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix (idempotent):
 * - undecided probation reviews of employees who already left (exited / transferred) are closed, so they leave every queue;
 * - stages skipped at hire because nobody could act then are reopened when someone now can.
 */
return new class extends Migration
{
    public function up(): void
    {
        $routing = app(ProbationRouting::class);

        $leavers = DB::table('probation_reviews')->join('employees', 'employees.id', '=', 'probation_reviews.employee_id')
            ->whereIn('probation_reviews.status', ProbationRouting::UNDECIDED)
            ->whereNotIn('employees.status', ['active', 'suspended'])
            ->distinct()->pluck('employees.status', 'employees.id');
        foreach ($leavers as $employeeId => $status) {
            $routing->closeForEmployee((int) $employeeId, $status === 'transferred' ? 'employee transferred' : 'employee exited');
        }

        ProbationReview::withoutGlobalScopes()->whereIn('status', ProbationRouting::UNDECIDED)->get()
            ->each(fn (ProbationReview $review) => $routing->reopenSkipped($review, notify: false));
    }

    public function down(): void
    {
        // Data fix only.
    }
};
