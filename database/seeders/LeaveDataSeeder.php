<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Modules\Leave\Models\LeaveBalance;
use App\Modules\Leave\Models\LeaveJobRun;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveTransaction;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveDayCounter;
use App\Modules\Leave\Services\LeaveJobs;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * Leave demo data for every company. Re-running rebuilds the same data set (deterministic random seed):
 *  - default leave types, prior-year balances with 12 monthly accruals, a real year-end carry-forward
 *    (forfeitures + roll-over), and current-year balances via the ledger
 *  - ~12 months of non-overlapping requests per active employee plus next month, across every status,
 *    with handovers, recalls and supporting documents; some approved leave covers this week
 *  - employee@hr.test has a request pending with supervisor@hr.test and a handover to answer
 */
class LeaveDataSeeder extends Seeder
{
    private LeaveBalanceService $balances;

    private LeaveDayCounter $counter;

    private const COMMENTS = [
        'ANNUAL' => ['Family holiday upcountry', 'Annual vacation', 'Travelling to Mombasa with family', 'Personal time off', 'Attending a family wedding', 'Rest and recharge', 'Moving house'],
        'SICK_FULL' => ['Flu — doctor\'s note attached', 'Medical procedure and recovery', 'Malaria treatment', 'Hospital admission'],
        'SICK_HALF' => ['Extended recovery after surgery', 'Ongoing treatment'],
        'STUDY' => ['CPA exams', 'Masters final exams', 'Professional certification exam', 'KASNEB papers'],
        'COMPASSIONATE' => ['Bereavement in the family', 'Funeral arrangements upcountry'],
        'FAMILY' => ['Child\'s school meeting', 'Family emergency', 'Taking a parent to hospital'],
        'UNPAID' => ['Extended personal travel', 'Personal matters'],
        'HOURLY' => ['Bank appointment', 'Doctor\'s appointment', 'School pick-up', 'Government office errand'],
        'MATERNITY' => ['Maternity leave'],
        'PATERNITY' => ['Paternity leave — new baby'],
    ];

    private const DUTIES = [
        'Respond to customer queries and escalate urgent issues',
        'Process daily reports and approvals in my queue',
        'Follow up on pending supplier invoices',
        'Attend the weekly team meeting and share minutes',
        'Monitor the shared mailbox and the ticket queue',
        'Handle branch cash reconciliation',
    ];

    public function run(): void
    {
        $this->balances = app(LeaveBalanceService::class);
        $this->counter = app(LeaveDayCounter::class);

        foreach (Company::orderBy('id')->get() as $company) {
            $this->seedCompany($company);
        }
    }

    private function seedCompany(Company $company): void
    {
        mt_srand(crc32('leave-'.$company->id));
        $today = today();
        $prevYear = $today->year - 1;

        // Rebuild: remove this company's leave data (documents too).
        LeaveTransaction::withoutGlobalScopes()->where('company_id', $company->id)->delete();
        LeaveRequest::withoutGlobalScopes()->where('company_id', $company->id)->delete();
        LeaveBalance::withoutGlobalScopes()->where('company_id', $company->id)->delete();
        LeaveJobRun::where('company_id', $company->id)->delete();
        Storage::disk('local')->deleteDirectory("leave/documents/{$company->id}");

        LeaveType::seedDefaults($company);
        $types = LeaveType::withoutGlobalScopes()->where('company_id', $company->id)->with('balanceType')->get()->keyBy('code');
        $company->load([]);
        $employees = Employee::withoutGlobalScopes()->where('company_id', $company->id)->where('status', 'active')
            ->with('company', 'user')->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }
        $byDept = $employees->groupBy('department_id');
        $hr = User::where('company_id', $company->id)->permission('leave.manage')->first();

        // Prior-year balances (opening + 12 accruals).
        $this->balances->initialiseAll($prevYear, $company->id);

        // ---- plan requests per employee
        $windowStart = $today->copy()->subMonths(12)->startOfMonth();
        $windowEnd = $today->copy()->addMonth()->endOfMonth();
        $plans = [];
        $demoEmployee = User::where('email', 'employee@hr.test')->first()?->employee;
        $demoSupervisor = User::where('email', 'supervisor@hr.test')->first()?->employee;

        foreach ($employees as $i => $employee) {
            $items = [];
            // Pinned demo requests.
            if ($demoEmployee && $employee->id === $demoEmployee->id) {
                $start = $this->workday($today->copy()->addMonth()->startOfMonth()->addDays(6));
                $items[] = ['code' => 'ANNUAL', 'start' => $start, 'len' => 4, 'status' => 'pending_supervisor', 'pinned' => true];
                $items[] = ['code' => 'FAMILY', 'start' => $this->workday($today->copy()->addDays(10)), 'len' => 1, 'status' => 'pending_handover', 'pinned' => true];
            }
            // ~1 in 7 employees on approved leave this week.
            if ($i % 7 === 3) {
                $start = $this->workday($today->copy()->startOfWeek()->subDays(mt_rand(0, 4)));
                $items[] = ['code' => mt_rand(1, 5) === 1 ? 'SICK_FULL' : 'ANNUAL', 'start' => $start, 'len' => mt_rand(3, 8), 'status' => 'approved', 'pinned' => true];
            }

            $joined = $employee->date_of_joining ? Carbon::parse($employee->date_of_joining)->addDays(30) : $windowStart;
            $cursor = $joined->max($windowStart)->copy();
            while (true) {
                $cursor = $cursor->copy()->addDays(mt_rand(18, 75));
                if ($cursor->gt($windowEnd)) {
                    break;
                }
                $code = $this->pickType($employee, $i);
                $len = match ($code) {
                    'ANNUAL' => mt_rand(1, 10), 'SICK_FULL' => mt_rand(1, 3), 'SICK_HALF' => mt_rand(1, 3),
                    'STUDY' => mt_rand(2, 5), 'COMPASSIONATE' => mt_rand(1, 3), 'FAMILY' => 1, 'UNPAID' => mt_rand(1, 4),
                    'HOURLY' => 1, 'MATERNITY' => 90, 'PATERNITY' => 14, default => 1,
                };
                $items[] = ['code' => $code, 'start' => $this->workday($cursor), 'len' => $len, 'status' => null, 'pinned' => false];
                $cursor = $cursor->copy()->addDays($len + 7);
            }

            // Resolve dates and drop overlaps (pinned items win).
            usort($items, fn ($a, $b) => [$b['pinned'], $a['start']] <=> [$a['pinned'], $b['start']]);
            $occupied = [];
            foreach ($items as $item) {
                $type = $types[$item['code']];
                $end = $type->counting === 'calendar'
                    ? $item['start']->copy()->addDays($item['len'] - 1)
                    : $this->addWorkdays($item['start'], $item['len'], $company);
                $clash = collect($occupied)->contains(fn ($o) => $item['start']->lte($o[1]) && $end->gte($o[0]));
                if ($clash || $end->gt($windowEnd->copy()->addMonths(3))) {
                    continue;
                }
                $occupied[] = [$item['start'], $end];
                $plans[] = $item + ['end' => $end, 'employee' => $employee];
            }
        }

        usort($plans, fn ($a, $b) => $a['start'] <=> $b['start']);

        // ---- prior-year requests, then the real year-end job, then current-year requests
        $prior = array_filter($plans, fn ($p) => $p['start']->year === $prevYear);
        $current = array_filter($plans, fn ($p) => $p['start']->year > $prevYear);
        foreach ($prior as $p) {
            $this->createRequest($company, $p, $types, $byDept, $hr, $demoEmployee, $demoSupervisor);
        }
        app(LeaveJobs::class)->carryForward($prevYear, $company->id, notify: false);
        foreach ($current as $p) {
            $this->createRequest($company, $p, $types, $byDept, $hr, $demoEmployee, $demoSupervisor);
        }

        // Accrual run history for the last 12 months. Current-year months are really accrued (a fake run
        // record without postings would make `leave:accrue` skip the month and leave balances stuck at 1.75).
        for ($m = 11; $m >= 0; $m--) {
            $period = $today->copy()->startOfMonth()->subMonths($m);
            if ($period->year === $today->year) {
                app(LeaveJobs::class)->accrue($period->format('Y-m'), $company->id, notify: false, force: true);
                LeaveJobRun::where(['job' => 'accrual', 'period' => $period->format('Y-m'), 'company_id' => $company->id])->update(['ran_at' => $period->copy()->setTime(0, 30)]);

                continue;
            }
            LeaveJobRun::firstOrCreate(
                ['job' => 'accrual', 'period' => $period->format('Y-m'), 'company_id' => $company->id],
                ['records' => $employees->count(), 'summary' => ['employees' => $employees->count(), 'days' => round($employees->count() * $types['ANNUAL']->monthlyAccrual($company), 2)], 'ran_at' => $period->copy()->setTime(0, 30)],
            );
        }

        $this->command?->info("Leave: {$company->name} — ".LeaveRequest::withoutGlobalScopes()->where('company_id', $company->id)->count().' requests, '
            .LeaveBalance::withoutGlobalScopes()->where('company_id', $company->id)->count().' balances.');
    }

    private function pickType(Employee $employee, int $i): string
    {
        $roll = mt_rand(1, 100);
        if ($roll <= 2 && $i % 9 === 0) {
            return $employee->gender === 'female' ? 'MATERNITY' : 'PATERNITY';
        }

        return match (true) {
            $roll <= 48 => 'ANNUAL',
            $roll <= 64 => 'SICK_FULL',
            $roll <= 68 => 'SICK_HALF',
            $roll <= 74 => 'STUDY',
            $roll <= 79 => 'COMPASSIONATE',
            $roll <= 85 => 'FAMILY',
            $roll <= 90 => 'UNPAID',
            default => 'HOURLY',
        };
    }

    private function createRequest(Company $company, array $p, $types, $byDept, ?User $hr, ?Employee $demoEmployee, ?Employee $demoSupervisor): void
    {
        $today = today();
        $employee = $p['employee'];
        $type = $types[$p['code']];
        $start = $p['start'];
        $end = $p['end'];
        $hourly = $type->allow_hours;
        $times = $hourly ? [['09:00', '11:00'], ['14:00', '17:00'], ['08:00', '10:30'], ['13:00', '15:00']][mt_rand(0, 3)] : [null, null];
        $halfDay = ! $hourly && $type->allow_half_day && $start->isSameDay($end) && mt_rand(1, 4) === 1;
        $count = $this->counter->forType($type, $company, $start, $end, $halfDay, $times[0], $times[1]);
        if ($count['days'] <= 0) {
            return;
        }

        $status = $p['status'] ?? $this->pickStatus($start, $end, $count['days']);

        // Respect balances: fall back to unpaid leave when the balance cannot cover it.
        if ($type->tracksBalance() && in_array($status, ['approved', 'recalled', 'pending_supervisor', 'pending_handover'], true)) {
            $balance = $this->balances->ensure($employee, $type->ledgerType(), $start->year);
            $usable = ($balance?->available ?? 0) - $this->balances->pendingDays($employee, $type->ledgerType(), $start->year);
            if ($usable < $count['days']) {
                if ($p['pinned'] && $balance) {
                    $this->balances->post($balance, 'adjustment', ceil($count['days'] - $usable), 'Opening balance reconciliation');
                } elseif ($hourly) {
                    return;
                } else {
                    $type = $types['UNPAID'];
                    $count = $this->counter->forType($type, $company, $start, $end, $halfDay);
                }
            }
        }

        // Handover colleagues.
        $colleagues = ($byDept[$employee->department_id] ?? collect())->where('id', '!=', $employee->id)->values();
        if ($colleagues->isEmpty()) {
            $colleagues = $byDept->flatten()->where('id', '!=', $employee->id)->values();
        }
        $h1 = null;
        $h2 = null;
        if ($colleagues->isNotEmpty() && ($status === 'pending_handover' || $status === 'handover_rejected' || mt_rand(1, 10) <= 6)) {
            $h1 = $colleagues[mt_rand(0, $colleagues->count() - 1)];
            if ($colleagues->count() > 2 && mt_rand(1, 6) === 1) {
                $h2 = $colleagues->where('id', '!=', $h1->id)->values()->random();
            }
        }
        // Demo: employee@hr.test's pending request was handed over to a colleague who already accepted.
        if ($demoEmployee && $employee->id === $demoEmployee->id && $status === 'pending_handover' && $demoSupervisor) {
            $h1 = $colleagues->first() ?? $h1;
        }
        // Demo: someone in employee@hr.test's team asks them to cover (handover queue populated).
        if ($demoEmployee && $status === 'pending_handover' && $employee->id !== $demoEmployee->id
            && $employee->department_id === $demoEmployee->department_id && ! LeaveRequest::withoutGlobalScopes()->where('handover1_id', $demoEmployee->id)->where('status', 'pending_handover')->exists()) {
            $h1 = $demoEmployee;
            $h2 = null;
        }
        if ($status === 'pending_handover' && ! $h1) {
            $status = 'pending_supervisor';
        }

        $handoverStatus = fn ($slotEmployee, $slot) => $slotEmployee ? match ($status) {
            'pending_handover' => $slot === 1 && $h2 && mt_rand(0, 1) ? 'approved' : 'pending',
            'handover_rejected' => $slot === 1 ? 'rejected' : 'approved',
            default => 'approved',
        } : null;

        $submitted = $start->copy()->subDays(mt_rand(3, 21))->setTime(mt_rand(8, 16), mt_rand(0, 59));
        if ($submitted->gt(now())) {
            $submitted = now()->subDays(mt_rand(0, 3))->subHours(mt_rand(1, 8));
        }
        if (in_array($status, ['pending_supervisor', 'pending_handover'], true) && $submitted->lt(now()->subDays(6))) {
            $submitted = now()->subDays(mt_rand(1, 5))->subHours(mt_rand(1, 8));
        }
        $decider = $employee->primary_supervisor_id ? Employee::withoutGlobalScopes()->find($employee->primary_supervisor_id)?->user : null;
        $decider ??= $hr;
        $decidedAt = $submitted->copy()->addDays(mt_rand(0, 2))->addHours(mt_rand(1, 6))->min(now());

        $leave = new LeaveRequest;
        $leave->forceFill([
            'company_id' => $company->id,
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'half_day' => $halfDay,
            'start_time' => $times[0],
            'end_time' => $times[1],
            'hours' => $count['hours'],
            'days' => $count['days'],
            'status' => $status,
            'handover1_id' => $h1?->id,
            'handover1_status' => $handoverStatus($h1, 1),
            'handover1_reason' => $status === 'handover_rejected' ? 'I am also scheduled to be away during this period.' : null,
            'handover1_at' => $h1 && $status !== 'pending_handover' ? $submitted->copy()->addHours(mt_rand(2, 20))->min(now()) : null,
            'handover2_id' => $h2?->id,
            'handover2_status' => $handoverStatus($h2, 2),
            'handover2_at' => $h2 && $status !== 'pending_handover' ? $submitted->copy()->addHours(mt_rand(2, 30))->min(now()) : null,
            'duties' => $h1 ? self::DUTIES[mt_rand(0, count(self::DUTIES) - 1)] : null,
            'comment' => mt_rand(1, 3) > 1 ? (self::COMMENTS[$type->code] ?? ['Personal'])[mt_rand(0, count(self::COMMENTS[$type->code] ?? ['x']) - 1)] : null,
            'supervisor_id' => $employee->primary_supervisor_id,
            'decided_by' => in_array($status, ['approved', 'rejected', 'recalled'], true) ? $decider?->id : null,
            'decided_at' => in_array($status, ['approved', 'rejected', 'recalled'], true) ? $decidedAt : null,
            'decision_note' => $status === 'rejected' ? ['Peak period — please pick other dates.', 'Team already short-staffed that week.', 'Quarter-end close; reschedule after the 5th.'][mt_rand(0, 2)] : null,
            'submitted_at' => $submitted,
            'cancelled_at' => $status === 'cancelled' ? $submitted->copy()->addDays(1)->min(now()) : null,
            'cancel_reason' => $status === 'cancelled' ? 'Plans changed' : null,
            'created_at' => $submitted,
            'updated_at' => in_array($status, ['pending_supervisor', 'pending_handover'], true) ? $submitted : $decidedAt,
        ]);
        $leave->saveQuietly();

        if ($type->requires_document) {
            $path = "leave/documents/{$company->id}/demo-{$leave->id}.pdf";
            Storage::disk('local')->put($path, $this->pdf($type->name.' — supporting document for '.$employee->full_name));
            $leave->forceFill(['document_path' => $path, 'document_name' => str($type->code)->lower().'_certificate.pdf'])->saveQuietly();
        }

        if (in_array($status, ['approved', 'recalled'], true) && $type->tracksBalance()) {
            $balance = $this->balances->ensure($employee, $type->ledgerType(), $start->year);
            $this->balances->post($balance, 'deduction', -$count['days'], "{$type->name} {$start->toDateString()} – {$end->toDateString()}", $decider?->id, $leave, "request:{$leave->id}", $start->copy());
            $leave->forceFill(['deducted_days' => $count['days'], 'balance_year' => $start->year])->saveQuietly();
        }

        if ($status === 'recalled') {
            $recallDate = $this->addWorkdays($start, max(1, (int) floor($count['days'] / 2)), $company);
            $used = $this->counter->forType($type, $company, $start, $recallDate)['days'];
            $credit = round(($type->tracksBalance() ? $count['days'] : $count['days']) - $used, 2);
            if ($credit > 0 && $type->tracksBalance()) {
                $balance = $this->balances->ensure($employee, $type->ledgerType(), $start->year);
                $this->balances->post($balance, 'recall_credit', $credit, 'Recalled from leave on '.$recallDate->toDateString().': urgent operational need', $hr?->id, $leave, "recall:{$leave->id}", $recallDate->copy());
            }
            $leave->forceFill([
                'original_end_date' => $end->toDateString(), 'original_days' => $count['days'],
                'end_date' => $recallDate->toDateString(), 'days' => $used, 'deducted_days' => $type->tracksBalance() ? $used : 0,
                'recall_date' => $recallDate->toDateString(), 'recalled_days' => max(0, $credit),
                'recalled_by' => $hr?->id ?? $decider?->id, 'recalled_at' => $recallDate->copy()->setTime(9, 0),
                'recall_reason' => 'Urgent operational need — audit preparation',
            ])->saveQuietly();
        }
    }

    private function pickStatus(Carbon $start, Carbon $end, float $days): string
    {
        $today = today();
        $r = mt_rand(1, 100);
        if ($end->lt($today)) {
            return match (true) {
                $r <= 76 => 'approved',
                $r <= 84 => 'rejected',
                $r <= 91 => 'cancelled',
                $r <= 96 => $days >= 4 ? 'recalled' : 'approved',
                default => 'handover_rejected',
            };
        }
        if ($start->lte($today)) {
            return 'approved';
        }

        return match (true) {
            $r <= 45 => 'approved',
            $r <= 72 => 'pending_supervisor',
            $r <= 92 => 'pending_handover',
            default => 'cancelled',
        };
    }

    /** First weekday on or after the date. */
    private function workday(Carbon $d): Carbon
    {
        $d = $d->copy()->startOfDay();
        while ($d->isWeekend()) {
            $d->addDay();
        }

        return $d;
    }

    /** Date of the n-th working day starting at $start (n = 1 returns $start). */
    private function addWorkdays(Carbon $start, int $n, Company $company): Carbon
    {
        $holidays = array_flip($this->counter->holidays($start, $start->copy()->addDays($n * 2 + 14), $company->id));
        $d = $start->copy();
        $counted = 0;
        while (true) {
            if (! $d->isWeekend() && ! isset($holidays[$d->toDateString()])) {
                $counted++;
                if ($counted >= $n) {
                    return $d;
                }
            }
            $d->addDay();
        }
    }

    /** A minimal one-page PDF so document downloads work in the demo. */
    private function pdf(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 14 Tf 72 720 Td ($text) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n$stream\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n$obj\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref\n0 '.(count($objects) + 1)."\n0000000000 65535 f \n";
        $out = str_replace('xref\n', "xref\n", $out);
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    }
}
