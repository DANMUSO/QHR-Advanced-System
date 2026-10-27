<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Payslip {{ $p['employee']['name'] }} {{ $p['run']['period_label'] }}</title>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 10px; color: #1e293b; margin: 0; }
    .header { border-bottom: 3px solid #4f46e5; padding-bottom: 10px; margin-bottom: 12px; }
    .header table { width: 100%; }
    .company { font-size: 16px; font-weight: bold; color: #0f172a; }
    .muted { color: #64748b; }
    .title { text-align: right; }
    .title h1 { font-size: 18px; margin: 0; color: #4f46e5; letter-spacing: 1px; }
    .info { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    .info td { padding: 3px 6px; vertical-align: top; }
    .info .k { color: #64748b; width: 18%; }
    .info .v { font-weight: bold; width: 32%; }
    .cols { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 0 -8px; }
    .cols > tbody > tr > td { vertical-align: top; width: 50%; }
    .box { border: 1px solid #e2e8f0; border-radius: 4px; margin-bottom: 10px; }
    .box h3 { margin: 0; padding: 5px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; background: #f1f5f9; color: #334155; }
    .lines { width: 100%; border-collapse: collapse; }
    .lines td { padding: 4px 8px; border-top: 1px solid #f1f5f9; }
    .lines td.amt { text-align: right; white-space: nowrap; }
    .lines tr.total td { font-weight: bold; border-top: 1px solid #cbd5e1; background: #f8fafc; }
    .net { background: #4f46e5; color: #fff; padding: 10px 12px; border-radius: 4px; margin: 6px 0 12px; }
    .net table { width: 100%; }
    .net .label { font-size: 11px; }
    .net .value { font-size: 18px; font-weight: bold; text-align: right; }
    .ytd { width: 100%; border-collapse: collapse; }
    .ytd th { font-size: 8px; text-transform: uppercase; color: #64748b; padding: 4px; text-align: right; border-bottom: 1px solid #e2e8f0; }
    .ytd td { padding: 4px; text-align: right; }
    .footer { margin-top: 14px; font-size: 8px; color: #94a3b8; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 6px; }
</style>
</head>
<body>
@php($m = fn ($v) => number_format((float) $v, 2))
<div class="header">
    <table><tr>
        <td>
            @if (!empty($p['logo']))<img src="{{ $p['logo'] }}" style="height:42px;margin-bottom:4px"><br>@endif
            <span class="company">{{ $p['company']['name'] }}</span><br>
            <span class="muted">{{ $p['company']['address'] }}@if($p['company']['tax_pin']) · PIN {{ $p['company']['tax_pin'] }}@endif</span>
        </td>
        <td class="title">
            <h1>PAYSLIP</h1>
            <span class="muted">{{ $p['run']['period_label'] }}@if($p['run']['type'] === 'off_cycle') · {{ ucwords(str_replace('_', ' ', $p['run']['off_cycle_reason'] ?? 'off-cycle')) }}@endif</span><br>
            <span class="muted">Pay date: {{ $p['run']['pay_date'] ? \Illuminate\Support\Carbon::parse($p['run']['pay_date'])->format('d M Y') : '—' }}</span>
        </td>
    </tr></table>
</div>

<table class="info">
    <tr><td class="k">Employee</td><td class="v">{{ $p['employee']['name'] }}</td><td class="k">Employee No.</td><td class="v">{{ $p['employee']['employee_number'] ?? '—' }}</td></tr>
    <tr><td class="k">Position</td><td class="v">{{ $p['employee']['position'] ?? '—' }}</td><td class="k">Department</td><td class="v">{{ $p['employee']['department'] ?? '—' }}</td></tr>
    <tr><td class="k">KRA PIN</td><td class="v">{{ $p['employee']['kra_pin'] ?? '—' }}</td><td class="k">NSSF No.</td><td class="v">{{ $p['employee']['nssf_number'] ?? '—' }}</td></tr>
    <tr><td class="k">SHIF No.</td><td class="v">{{ $p['employee']['shif_number'] ?? '—' }}</td><td class="k">Paid via</td><td class="v">{{ $p['payment']['label'] }}</td></tr>
    <tr><td class="k">Days worked</td><td class="v">{{ rtrim(rtrim(number_format($p['days']['worked'], 2), '0'), '.') }} / {{ rtrim(rtrim(number_format($p['days']['working_days'], 2), '0'), '.') }} working days</td><td class="k">Calendar days</td><td class="v">{{ rtrim(rtrim(number_format($p['days']['in_period'], 2), '0'), '.') }}</td></tr>
</table>

<table class="cols"><tbody><tr>
    <td>
        <div class="box">
            <h3>Earnings</h3>
            <table class="lines">
                @foreach ($p['earnings'] as $i)<tr><td>{{ $i['name'] }}</td><td class="amt">{{ $m($i['amount']) }}</td></tr>@endforeach
                <tr class="total"><td>Gross pay</td><td class="amt">{{ $m($p['totals']['gross_pay']) }}</td></tr>
            </table>
        </div>
        <div class="box">
            <h3>Tax computation</h3>
            <table class="lines">
                <tr><td>Gross pay</td><td class="amt">{{ $m($p['totals']['gross_pay']) }}</td></tr>
                @if ($p['totals']['non_taxable_pay'] > 0)<tr><td>Less non-taxable pay</td><td class="amt">({{ $m($p['totals']['non_taxable_pay']) }})</td></tr>@endif
                <tr><td>Less allowable deductions</td><td class="amt">({{ $m($p['totals']['allowable_deductions']) }})</td></tr>
                <tr><td>Taxable pay</td><td class="amt">{{ $m($p['totals']['taxable_pay']) }}</td></tr>
                <tr><td>Tax charged</td><td class="amt">{{ $m($p['totals']['tax_charged']) }}</td></tr>
                <tr><td>Personal relief</td><td class="amt">({{ $m($p['totals']['personal_relief']) }})</td></tr>
                @if ($p['totals']['insurance_relief'] > 0)<tr><td>Insurance relief</td><td class="amt">({{ $m($p['totals']['insurance_relief']) }})</td></tr>@endif
                <tr class="total"><td>PAYE</td><td class="amt">{{ $m($p['totals']['paye']) }}</td></tr>
            </table>
        </div>
    </td>
    <td>
        <div class="box">
            <h3>Statutory deductions</h3>
            <table class="lines">
                @foreach ($p['statutory'] as $i)<tr><td>{{ $i['name'] }}</td><td class="amt">{{ $m($i['amount']) }}</td></tr>@endforeach
            </table>
        </div>
        @if (count($p['deductions']))
        <div class="box">
            <h3>Other deductions</h3>
            <table class="lines">
                @foreach ($p['deductions'] as $i)<tr><td>{{ $i['name'] }}</td><td class="amt">{{ $m($i['amount']) }}</td></tr>@endforeach
            </table>
        </div>
        @endif
        <div class="box">
            <table class="lines"><tr class="total"><td>Total deductions</td><td class="amt">{{ $m($p['totals']['total_deductions']) }}</td></tr></table>
        </div>
        @if (count($p['employer']))
        <div class="box">
            <h3>Employer contributions (not deducted)</h3>
            <table class="lines">
                @foreach ($p['employer'] as $i)<tr><td>{{ $i['name'] }}</td><td class="amt">{{ $m($i['amount']) }}</td></tr>@endforeach
            </table>
        </div>
        @endif
    </td>
</tr></tbody></table>

@if (!empty($p['reimbursements']))
<div class="box">
    <h3>Reimbursements (non-taxable, added to net pay)</h3>
    <table class="lines">
        @foreach ($p['reimbursements'] as $i)<tr><td>{{ $i['name'] }}</td><td class="amt">{{ $m($i['amount']) }}</td></tr>@endforeach
    </table>
</div>
@endif

<div class="net"><table><tr>
    <td class="label">NET PAY ({{ $p['company']['currency'] }})</td>
    <td class="value">{{ $m($p['totals']['net_pay']) }}</td>
</tr></table></div>

<div class="box">
    <h3>Year to date ({{ substr($p['run']['period'], 0, 4) }})</h3>
    <table class="ytd">
        <tr><th>Gross</th><th>Taxable</th><th>PAYE</th><th>NSSF</th><th>SHIF</th><th>Housing Levy</th><th>Pension</th><th>Net pay</th></tr>
        <tr>
            <td>{{ $m($p['ytd']['gross_pay']) }}</td><td>{{ $m($p['ytd']['taxable_pay']) }}</td><td>{{ $m($p['ytd']['paye']) }}</td>
            <td>{{ $m($p['ytd']['nssf']) }}</td><td>{{ $m($p['ytd']['shif']) }}</td><td>{{ $m($p['ytd']['ahl']) }}</td>
            <td>{{ $m($p['ytd']['pension']) }}</td><td>{{ $m($p['ytd']['net_pay']) }}</td>
        </tr>
    </table>
</div>

<div class="footer">
    This is a computer-generated payslip and does not require a signature. Generated {{ now()->format('d M Y H:i') }}. Treat as confidential.
</div>
</body>
</html>
