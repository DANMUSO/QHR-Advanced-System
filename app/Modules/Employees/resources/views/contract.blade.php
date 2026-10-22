<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Contract {{ $contract->reference }}</title>
<style>
    @page { margin: 36px 48px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1e293b; line-height: 1.55; }
    .head { border-bottom: 3px solid {{ $company?->brand_color ?? '#4f46e5' }}; padding-bottom: 10px; margin-bottom: 18px; }
    .head h1 { margin: 0; font-size: 19px; }
    .head p { margin: 2px 0 0; font-size: 10px; color: #64748b; }
    h2 { font-size: 14px; text-align: center; margin: 6px 0 16px; letter-spacing: .5px; }
    h3 { font-size: 11.5px; margin: 16px 0 6px; }
    table.terms { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    table.terms td { border: 1px solid #e2e8f0; padding: 6px 8px; }
    table.terms td.k { width: 34%; background: #f8fafc; font-weight: bold; }
    p { margin: 0 0 8px; text-align: justify; }
    .sig { width: 100%; margin-top: 40px; }
    .sig td { width: 50%; vertical-align: top; padding-right: 24px; }
    .sig .line { border-top: 1px solid #334155; margin-top: 40px; padding-top: 4px; }
    .foot { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
    <div class="head">
        <h1>{{ $company?->name }}</h1>
        <p>{{ collect([$company?->address, $company?->location, $company?->phone, $company?->email])->filter()->implode(' · ') }}</p>
    </div>

    <h2>CONTRACT OF EMPLOYMENT ({{ strtoupper($typeLabel) }})</h2>

    <p>This contract is made between <strong>{{ $company?->name }}</strong> ("the Employer") and
        <strong>{{ trim($employee->first_name.' '.$employee->other_names.' '.$employee->last_name) }}</strong>
        @if ($employee->national_id)(ID No. {{ $employee->national_id }})@endif ("the Employee"), in accordance with the Employment Act, 2007.</p>

    <h3>1. Particulars of employment</h3>
    <table class="terms">
        <tr><td class="k">Contract reference</td><td>{{ $contract->reference }}</td></tr>
        <tr><td class="k">Employee number</td><td>{{ $employee->employee_number }}</td></tr>
        <tr><td class="k">Job title</td><td>{{ $contract->job_title ?? $employee->position?->title }}</td></tr>
        <tr><td class="k">Department / Station</td><td>{{ collect([$employee->department?->name, $employee->branch?->name])->filter()->implode(' / ') ?: '—' }}</td></tr>
        <tr><td class="k">Contract type</td><td>{{ $typeLabel }}</td></tr>
        <tr><td class="k">Commencement date</td><td>{{ $contract->start_date->format('jS F Y') }}</td></tr>
        <tr><td class="k">End date</td><td>{{ $contract->end_date?->format('jS F Y') ?? 'Not applicable (open-ended)' }}</td></tr>
        @if ($employee->probation_months)
            <tr><td class="k">Probation</td><td>{{ $employee->probation_months }} month(s){{ $employee->probation_end_date ? ', ending '.$employee->probation_end_date->format('jS F Y') : '' }}</td></tr>
        @endif
        @if ($contract->gross_salary !== null)
            <tr><td class="k">Gross monthly remuneration</td><td>{{ $currency }} {{ number_format((float) $contract->gross_salary, 2) }}, subject to statutory deductions</td></tr>
        @endif
    </table>

    <h3>2. Duties</h3>
    <p>The Employee shall perform the duties of the position above and any other lawful duties reasonably assigned by the Employer, and shall report to the supervisor designated by the Employer.</p>

    <h3>3. Hours, leave and benefits</h3>
    <p>Working hours, annual leave and other benefits are as set out in the Employer's HR policies, which may be amended from time to time, and shall not be less favourable than the minimum provided by law.</p>

    <h3>4. Termination</h3>
    <p>@if ($contract->contract_type === 'casual')This casual engagement may be terminated by either party at the end of any day without notice.@else Either party may terminate this contract by giving not less than one (1) month's written notice or payment in lieu of notice, save for summary dismissal as provided by the Employment Act, 2007.@endif</p>

    <h3>5. Confidentiality</h3>
    <p>The Employee shall not, during or after employment, disclose any confidential information of the Employer obtained in the course of employment.</p>

    @if ($contract->notes)
        <h3>6. Special terms</h3>
        <p>{!! nl2br(e($contract->notes)) !!}</p>
    @endif

    <table class="sig">
        <tr>
            <td><div class="line">For the Employer<br>{{ $company?->name }}</div><p>Date: ____________________</p></td>
            <td><div class="line">Employee<br>{{ trim($employee->first_name.' '.$employee->last_name) }}</div><p>Date: ____________________</p></td>
        </tr>
    </table>

    <div class="foot">{{ $company?->name }} · {{ $contract->reference }} · {{ ucfirst($contract->status) }}</div>
</body>
</html>
