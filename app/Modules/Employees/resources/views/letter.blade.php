@php
    $addressed = in_array($letter->template, ['probation_confirmation', 'promotion', 'transfer', 'warning'], true);
@endphp
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $letter->subject }}</title>
<style>
    @page { margin: 36px 48px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11.5px; color: #1e293b; line-height: 1.6; }
    .head { border-bottom: 3px solid {{ $company?->brand_color ?? '#4f46e5' }}; padding-bottom: 10px; margin-bottom: 24px; }
    .head h1 { margin: 0; font-size: 20px; color: #0f172a; }
    .head p { margin: 2px 0 0; font-size: 10px; color: #64748b; }
    .meta { width: 100%; margin-bottom: 20px; font-size: 11px; }
    .meta td { padding: 0; vertical-align: top; }
    .subject { font-weight: bold; text-decoration: underline; margin: 18px 0 12px; }
    .body p { margin: 0 0 12px; text-align: justify; }
    .sign { margin-top: 40px; }
    .sign .line { width: 200px; border-top: 1px solid #334155; margin-top: 42px; padding-top: 4px; }
    .foot { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 9px; color: #94a3b8; text-align: center; }
</style>
</head>
<body>
    <div class="head">
        <h1>{{ $company?->name }}</h1>
        <p>{{ collect([$company?->address, $company?->location])->filter()->implode(' · ') }}</p>
        <p>{{ collect([$company?->phone, $company?->email])->filter()->implode(' · ') }}</p>
    </div>

    <table class="meta">
        <tr>
            <td>Ref: {{ $letter->reference }}</td>
            <td style="text-align:right">{{ ($letter->effective_date ?? $letter->created_at)->format('jS F Y') }}</td>
        </tr>
    </table>

    @if ($addressed)
        <p>
            {{ trim($employee->first_name.' '.$employee->last_name) }}<br>
            Employee No. {{ $employee->employee_number }}<br>
            @if ($employee->postal_address){{ $employee->postal_address }}<br>@endif
        </p>
        <p>Dear {{ $employee->first_name }},</p>
    @else
        <p><strong>TO WHOM IT MAY CONCERN</strong></p>
    @endif

    <div class="subject">RE: {{ $letter->subject }}</div>

    <div class="body">
        @foreach (preg_split("/\n\s*\n/", $letter->body) as $para)
            <p>{!! nl2br(e(trim($para))) !!}</p>
        @endforeach
    </div>

    <div class="sign">
        <p>Yours faithfully,<br>For and on behalf of {{ $company?->name }}</p>
        <div class="line">
            {{ $signatory?->name ?? 'Human Resources' }}<br>
            Human Resources
        </div>
    </div>

    <div class="foot">{{ $company?->name }} · {{ $letter->reference }} · Generated {{ $letter->created_at->format('d M Y H:i') }}</div>
</body>
</html>
