<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>P9A {{ $d['year'] }} {{ $d['employee']['name'] }}</title>
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { font-size: 8.5px; color: #1e293b; }
    h1 { font-size: 14px; margin: 0; color: #0f172a; }
    .sub { color: #64748b; margin: 2px 0 10px; }
    .meta { width: 100%; margin-bottom: 10px; }
    .meta td { padding: 2px 4px; }
    .meta .k { color: #64748b; }
    .meta .v { font-weight: bold; }
    table.card { width: 100%; border-collapse: collapse; }
    table.card th { background: #eef2ff; color: #3730a3; font-size: 7.5px; padding: 5px 3px; border: 1px solid #c7d2fe; text-align: center; }
    table.card td { padding: 4px 3px; border: 1px solid #e2e8f0; text-align: right; }
    table.card td.m { text-align: left; font-weight: bold; }
    table.card tr.total td { font-weight: bold; background: #f8fafc; border-top: 2px solid #94a3b8; }
    .note { margin-top: 10px; color: #64748b; font-size: 7.5px; }
</style>
</head>
<body>
@php($m = fn ($v) => number_format((float) $v, 2))
<table style="width:100%"><tr>
    <td>
        <h1>P9A — Tax Deduction Card {{ $d['year'] }}</h1>
        <div class="sub">Kenya Revenue Authority · Income Tax Department (generated from approved payroll)</div>
    </td>
    <td style="text-align:right">@if (!empty($d['logo']))<img src="{{ $d['logo'] }}" style="height:36px">@endif</td>
</tr></table>
<table class="meta">
    <tr><td class="k">Employer's name</td><td class="v">{{ $d['employer']['name'] }}</td><td class="k">Employer's PIN</td><td class="v">{{ $d['employer']['pin'] ?? '—' }}</td></tr>
    <tr><td class="k">Employee's name</td><td class="v">{{ $d['employee']['name'] }} ({{ $d['employee']['employee_number'] }})</td><td class="k">Employee's PIN</td><td class="v">{{ $d['employee']['kra_pin'] ?? '—' }}</td></tr>
</table>
<table class="card">
    <tr>
        <th>Month</th><th>A<br>Basic salary</th><th>B<br>Benefits / allowances</th><th>C<br>Non-taxable</th><th>D<br>Total gross</th>
        <th>E<br>Retirement (NSSF + pension)</th><th>F<br>SHIF</th><th>G<br>Housing levy</th><th>H<br>Allowable deductions</th>
        <th>I<br>Chargeable pay</th><th>J<br>Tax charged</th><th>K<br>Personal relief</th><th>L<br>Insurance relief</th><th>M<br>PAYE</th>
    </tr>
    @foreach ($d['rows'] as $r)
    <tr>
        <td class="m">{{ $r['month'] }}</td><td>{{ $m($r['basic']) }}</td><td>{{ $m($r['benefits']) }}</td><td>{{ $m($r['non_taxable']) }}</td>
        <td>{{ $m($r['gross']) }}</td><td>{{ $m($r['retirement']) }}</td><td>{{ $m($r['shif']) }}</td><td>{{ $m($r['ahl']) }}</td>
        <td>{{ $m($r['allowable']) }}</td><td>{{ $m($r['chargeable']) }}</td><td>{{ $m($r['tax_charged']) }}</td>
        <td>{{ $m($r['personal_relief']) }}</td><td>{{ $m($r['insurance_relief']) }}</td><td>{{ $m($r['paye']) }}</td>
    </tr>
    @endforeach
    <tr class="total">
        <td class="m">Total</td>
        @foreach (['basic','benefits','non_taxable','gross','retirement','shif','ahl','allowable','chargeable','tax_charged','personal_relief','insurance_relief','paye'] as $k)
            <td>{{ $m($d['totals'][$k]) }}</td>
        @endforeach
    </tr>
</table>
<div class="note">
    Total chargeable pay: KES {{ $m($d['totals']['chargeable']) }} · Total tax (PAYE) deducted: KES {{ $m($d['totals']['paye']) }}.
    Rates follow the statutory tables configured in HR Advanced; verify against current KRA publications. Generated {{ now()->format('d M Y H:i') }}.
</div>
</body>
</html>
