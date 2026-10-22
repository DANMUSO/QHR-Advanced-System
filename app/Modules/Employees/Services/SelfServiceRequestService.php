<?php

namespace App\Modules\Employees\Services;

use App\Models\Employee;
use App\Models\User;
use App\Modules\Employees\Models\EmployeeLetter;
use App\Modules\Employees\Models\ExitRecord;
use App\Modules\Employees\Models\LetterRequest;
use App\Modules\Employees\Models\ProfileChangeRequest;
use App\Modules\Employees\Models\ResignationRequest;
use App\Modules\Employees\Notifications\SelfServiceNotification;
use App\Modules\Employees\Support\EmployeeRules;
use App\Modules\Payroll\Models\PayrollPayProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service requests decided by HR: sensitive profile changes (applied on approval, pay details straight to the
 * Payroll pay profile), resignations (converted to an exit by HR) and letter requests (issued with LetterService).
 */
class SelfServiceRequestService
{
    public function __construct(private EmployeeService $employees, private LetterService $letters) {}

    /* ---------------------------------------------------------------- profile changes */

    /**
     * The sensitive values in $input that differ from what is on file:
     * ['kra_pin' => 'A1…', 'next_of_kin' => [rows], 'payment' => [details]]. Keys absent from $input are ignored.
     */
    public function diff(Employee $e, array $input): array
    {
        $changes = [];
        foreach (ProfileChangeRequest::STATUTORY as $field) {
            if (array_key_exists($field, $input) && $this->clean($input[$field]) !== $this->clean($e->{$field})) {
                $changes[$field] = $this->clean($input[$field]);
            }
        }

        if (array_key_exists('next_of_kin', $input) && is_array($input['next_of_kin'])) {
            $rows = $this->nokRows($input['next_of_kin']);
            if ($rows !== $this->nokRows($e->nextOfKin()->get()->toArray())) {
                $changes['next_of_kin'] = $rows;
            }
        }

        if (! empty($input['payment']) && is_array($input['payment'])) {
            $payment = $this->paymentRow($input['payment']);
            if ($payment !== $this->paymentRow($this->payment($e) ?? [])) {
                $changes['payment'] = $payment;
            }
        }

        return $changes;
    }

    /** Validates the changed values only, so legacy data elsewhere on the record never blocks a request. */
    public function validate(Employee $e, array $changes): void
    {
        $rules = Arr::only(EmployeeRules::statutory($e->company_id, $e), array_keys($changes));
        if (array_key_exists('next_of_kin', $changes)) {
            $rules += Arr::where(EmployeeRules::family(), fn ($r, $k) => str_starts_with($k, 'next_of_kin'));
        }
        if (array_key_exists('payment', $changes)) {
            $rules += [
                'payment.payment_method' => ['required', Rule::in(['bank', 'mpesa'])],
                'payment.bank_name' => ['nullable', 'required_if:payment.payment_method,bank', 'string', 'max:100'],
                'payment.bank_code' => ['nullable', 'string', 'max:20'],
                'payment.bank_branch' => ['nullable', 'string', 'max:100'],
                'payment.branch_code' => ['nullable', 'string', 'max:20'],
                'payment.account_name' => ['nullable', 'string', 'max:100'],
                'payment.account_number' => ['nullable', 'required_if:payment.payment_method,bank', 'string', 'max:40', 'regex:/^[0-9A-Za-z\- ]+$/'],
                'payment.mpesa_phone' => ['nullable', 'required_if:payment.payment_method,mpesa', 'string', 'regex:/^(\+?254|0)(7|1)\d{8}$/'],
            ];
        }

        Validator::make($changes, $rules, EmployeeRules::messages() + [
            'payment.mpesa_phone.regex' => 'Enter a Kenyan mobile number, e.g. 0712345678.',
            'payment.bank_name.required_if' => 'Enter the bank name.',
            'payment.account_number.required_if' => 'Enter the account number.',
            'payment.mpesa_phone.required_if' => 'Enter the M-Pesa number.',
        ], EmployeeRules::attributes())->validate();
    }

    /** Opens (or adds to) the employee's pending change request and tells HR. */
    public function submitChange(Employee $e, array $changes, User $by, ?string $note = null): ProfileChangeRequest
    {
        $request = DB::transaction(function () use ($e, $changes, $note) {
            $pending = ProfileChangeRequest::where('employee_id', $e->id)->where('status', 'pending')->lockForUpdate()->first();
            $previous = $this->previous($e, array_keys($changes));
            if ($pending) {
                $pending->update([
                    'changes' => array_merge($pending->changes ?? [], $changes),
                    'previous' => ($pending->previous ?? []) + $previous,
                    'note' => $note ?: $pending->note,
                ]);

                return $pending;
            }

            $request = new ProfileChangeRequest(['employee_id' => $e->id, 'status' => 'pending', 'changes' => $changes, 'previous' => $previous, 'note' => $note]);
            $request->forceFill(['company_id' => $e->company_id])->save();

            return $request;
        });
        activity('employees')->performedOn($e)->causedBy($by)->withProperties(['request_id' => $request->id, 'fields' => array_keys($changes)])
            ->log('Requested profile change: '.$request->summary());

        $this->notifyHr($e, $by, new SelfServiceNotification('Profile change to approve',
            "{$e->full_name} asked to change: {$request->summary()}.", '/employees/requests?tab=profile', 'user-pen'));

        return $request;
    }

    public function approveChange(ProfileChangeRequest $request, User $by): ProfileChangeRequest
    {
        $this->assertPending($request->status, $request->employee?->user_id, $by);
        $e = $request->employee;
        $changes = $request->changes ?? [];
        // Re-check (uniqueness may have changed since the request was raised).
        $this->validate($e, $changes);

        DB::transaction(function () use ($request, $e, $changes, $by) {
            $statutory = Arr::only($changes, ProfileChangeRequest::STATUTORY);
            if ($statutory) {
                $this->employees->update($e, $statutory, array_keys($statutory));
            }
            if (array_key_exists('next_of_kin', $changes)) {
                $this->employees->syncChildren($e, ['next_of_kin' => $changes['next_of_kin']]);
            }
            if (array_key_exists('payment', $changes)) {
                $this->applyPayment($e, $changes['payment']);
            }
            $request->update(['status' => 'approved', 'decided_by' => $by->id, 'decided_at' => now()]);
            activity('employees')->performedOn($e)->causedBy($by)->withProperties(['request_id' => $request->id])
                ->log('Approved profile change: '.$request->summary());
        });

        $this->notifyEmployee($e, new SelfServiceNotification('Profile change approved',
            "Your change to {$request->summary()} has been approved and applied.", '/portal?tab=profile', 'check-circle'));

        return $request;
    }

    public function rejectChange(ProfileChangeRequest $request, User $by, string $reason): ProfileChangeRequest
    {
        $this->assertPending($request->status, $request->employee?->user_id, $by);
        $request->update(['status' => 'rejected', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
        activity('employees')->performedOn($request->employee)->causedBy($by)->withProperties(['request_id' => $request->id, 'reason' => $reason])
            ->log('Rejected profile change: '.$request->summary());
        $this->notifyEmployee($request->employee, new SelfServiceNotification('Profile change rejected',
            "Your change to {$request->summary()} was not approved. Reason: {$reason}", '/portal?tab=profile', 'x-circle'));

        return $request;
    }

    /** Current pay details from the Payroll pay profile, or null when there is none. */
    public function payment(Employee $e): ?array
    {
        if (! class_exists(PayrollPayProfile::class)) {
            return null;
        }
        $p = PayrollPayProfile::withoutGlobalScope('company')->where('employee_id', $e->id)->first();

        return $p ? $p->only(ProfileChangeRequest::PAYMENT) : null;
    }

    /** Pay details for the employee's own screen, account numbers masked. */
    public function maskedPayment(Employee $e): ?array
    {
        $p = $this->payment($e);
        if (! $p) {
            return null;
        }

        return array_merge($p, ['account_number' => $this->mask($p['account_number']), 'mpesa_phone' => $this->mask($p['mpesa_phone'], 3)]);
    }

    private function applyPayment(Employee $e, array $payment): void
    {
        $profile = PayrollPayProfile::withoutGlobalScope('company')->firstOrNew(['employee_id' => $e->id]);
        if (! $profile->exists) {
            $profile->forceFill(['company_id' => $e->company_id, 'basic_salary' => 0]);
        }
        $profile->fill(Arr::only($payment, ProfileChangeRequest::PAYMENT));
        $profile->save();
    }

    private function previous(Employee $e, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = match ($key) {
                'next_of_kin' => $this->nokRows($e->nextOfKin()->get()->toArray()),
                'payment' => $this->payment($e),
                default => $e->{$key},
            };
        }

        return $out;
    }

    private function nokRows(array $rows): array
    {
        return collect($rows)->map(fn ($r) => ['id' => isset($r['id']) && $r['id'] ? (int) $r['id'] : null]
            + collect(EmployeeService::NOK_FIELDS)->mapWithKeys(fn ($f) => [$f => $this->clean($r[$f] ?? null)])->all())
            ->values()->all();
    }

    private function paymentRow(array $p): array
    {
        $method = $p['payment_method'] ?? null;
        $keys = $method === 'mpesa' ? ['payment_method', 'mpesa_phone']
            : ['payment_method', 'bank_name', 'bank_code', 'bank_branch', 'branch_code', 'account_name', 'account_number'];

        return collect($keys)->mapWithKeys(fn ($k) => [$k => $this->clean($p[$k] ?? null)])->all();
    }

    private function clean($v): ?string
    {
        $v = is_string($v) ? trim($v) : $v;

        return $v === null || $v === '' ? null : (string) $v;
    }

    private function mask(?string $v, int $keep = 4): ?string
    {
        if (! $v) {
            return $v;
        }

        return str_repeat('•', max(strlen($v) - $keep, 0)).substr($v, -$keep);
    }

    /* ---------------------------------------------------------------- resignations */

    public function resign(Employee $e, array $data, $letter, User $by): ResignationRequest
    {
        abort_unless($e->status === 'active', 422, 'Only active employees can resign.');
        abort_if(ResignationRequest::where('employee_id', $e->id)->where('status', 'pending')->exists(), 422,
            'You already have a resignation awaiting HR. Withdraw it first to change the details.');
        abort_if(ExitRecord::where('employee_id', $e->id)->where('status', 'scheduled')->exists(), 422,
            'HR has already scheduled your exit. Contact HR to change it.');

        $r = new ResignationRequest([
            'employee_id' => $e->id, 'status' => 'pending', 'notice_date' => $data['notice_date'] ?? now()->toDateString(),
            'last_working_day' => $data['last_working_day'], 'reason' => $data['reason'],
        ]);
        if ($letter) {
            $r->letter_path = $letter->store("employees/resignations/{$e->id}", 'local');
            $r->letter_name = $letter->getClientOriginalName();
        }
        $r->forceFill(['company_id' => $e->company_id])->save();
        activity('employees')->performedOn($e)->causedBy($by)->withProperties(['resignation_id' => $r->id, 'last_working_day' => $r->last_working_day->toDateString()])
            ->log('Submitted resignation');

        $this->notifyHr($e, $by, new SelfServiceNotification('Resignation received',
            "{$e->full_name} resigned. Last working day: {$r->last_working_day->format('d M Y')}.", '/employees/requests?tab=resignations', 'log-out'));
        if ($sup = $e->primarySupervisor?->user) {
            $sup->notify(new SelfServiceNotification('Resignation from your team',
                "{$e->full_name} has resigned. Last working day: {$r->last_working_day->format('d M Y')}.", '/portal', 'log-out'));
        }

        return $r;
    }

    public function withdrawResignation(ResignationRequest $r, User $by): void
    {
        abort_unless($r->status === 'pending', 422, 'Only a pending resignation can be withdrawn.');
        $r->update(['status' => 'withdrawn', 'decided_at' => now(), 'decided_by' => $by->id]);
        activity('employees')->performedOn($r->employee)->causedBy($by)->log('Withdrew resignation');
        $this->notifyHr($r->employee, $by, new SelfServiceNotification('Resignation withdrawn',
            "{$r->employee->full_name} withdrew their resignation.", '/employees/requests?tab=resignations', 'undo-2'));
    }

    public function declineResignation(ResignationRequest $r, User $by, string $reason): void
    {
        $this->assertPending($r->status, $r->employee?->user_id, $by);
        $r->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
        activity('employees')->performedOn($r->employee)->causedBy($by)->withProperties(['reason' => $reason])->log('Resignation returned by HR');
        $this->notifyEmployee($r->employee, new SelfServiceNotification('Resignation returned',
            "HR returned your resignation. Reason: {$reason}", '/portal?tab=requests', 'x-circle'));
    }

    /** Called when HR records the employee's exit (scheduled or immediate), and again when it completes: closes their pending resignation and links it to the exit. */
    public function markExited(Employee $e, int $exitId): void
    {
        ResignationRequest::withoutGlobalScope('company')->where('employee_id', $e->id)->where('status', 'pending')
            ->update(['status' => 'processed', 'exit_id' => $exitId, 'decided_at' => now(), 'decided_by' => auth()->id()]);
    }

    /* ---------------------------------------------------------------- letter requests */

    public function requestLetter(Employee $e, array $data, User $by): LetterRequest
    {
        $r = new LetterRequest(Arr::only($data, ['template', 'addressed_to', 'purpose']) + ['employee_id' => $e->id, 'status' => 'pending']);
        $r->forceFill(['company_id' => $e->company_id])->save();
        $name = LetterService::TEMPLATES[$r->template][0] ?? $r->template;
        activity('employees')->performedOn($e)->causedBy($by)->withProperties(['letter_request_id' => $r->id])->log("Requested letter: {$name}");
        $this->notifyHr($e, $by, new SelfServiceNotification('Letter requested',
            "{$e->full_name} requested a {$name}.", '/employees/requests?tab=letters', 'file-text'));

        return $r;
    }

    public function issueLetter(LetterRequest $r, User $by, ?string $effectiveDate, ?string $notes): EmployeeLetter
    {
        $this->assertPending($r->status, $r->employee?->user_id, $by);
        // No-objection letters print the request itself; the others take HR's optional notes only.
        $notes = $notes ?: ($r->template === 'no_objection' ? $r->purpose : null);
        $letter = DB::transaction(function () use ($r, $by, $effectiveDate, $notes) {
            $letter = $this->letters->generate($r->employee, $r->template, $effectiveDate, $notes, $by);
            $r->update(['status' => 'issued', 'letter_id' => $letter->id, 'decided_by' => $by->id, 'decided_at' => now()]);

            return $letter;
        });
        $this->notifyEmployee($r->employee, new SelfServiceNotification('Your letter is ready',
            "Your ".(LetterService::TEMPLATES[$r->template][0] ?? "letter")." has been issued. Download it from My Portal.", '/portal?tab=requests', 'file-check'));

        return $letter;
    }

    public function declineLetter(LetterRequest $r, User $by, string $reason): void
    {
        $this->assertPending($r->status, $r->employee?->user_id, $by);
        $r->update(['status' => 'declined', 'decided_by' => $by->id, 'decided_at' => now(), 'decision_note' => $reason]);
        $this->notifyEmployee($r->employee, new SelfServiceNotification('Letter request declined',
            "Your letter request was declined. Reason: {$reason}", '/portal?tab=requests', 'x-circle'));
    }

    /* ---------------------------------------------------------------- helpers */

    private function assertPending(string $status, ?int $ownerUserId, User $by): void
    {
        abort_unless($status === 'pending', 422, 'This request has already been decided.');
        abort_if($ownerUserId && $ownerUserId === $by->id, 403, 'You cannot decide your own request.');
    }

    /** HR officers of the employee's company (anyone who can update employee records), never the requester. */
    public function hrUsers(Employee $e, ?User $except = null): Collection
    {
        return User::permission('employees.update')->where('company_id', $e->company_id)->where('is_active', true)->get()
            ->reject(fn (User $u) => $u->id === $except?->id || $u->id === $e->user_id)->values();
    }

    private function notifyHr(Employee $e, User $by, SelfServiceNotification $n): void
    {
        $users = $this->hrUsers($e, $by);
        if ($users->isNotEmpty()) {
            Notification::send($users, $n);
        }
    }

    private function notifyEmployee(?Employee $e, SelfServiceNotification $n): void
    {
        $e?->user?->notify($n);
    }
}
