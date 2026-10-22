<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; line-height: 1.6; margin: 32px 40px; }
        .header { border-bottom: 3px solid {{ $offer->company?->brand_color ?? '#4f46e5' }}; padding-bottom: 12px; margin-bottom: 24px; }
        .company { font-size: 20px; font-weight: bold; color: {{ $offer->company?->brand_color ?? '#4f46e5' }}; }
        .muted { color: #64748b; font-size: 11px; }
        h2 { font-size: 15px; margin: 24px 0 8px; }
        table.terms { width: 100%; border-collapse: collapse; margin: 12px 0; }
        table.terms td { padding: 7px 10px; border: 1px solid #e2e8f0; }
        table.terms td.k { background: #f8fafc; width: 38%; font-weight: bold; }
        .sign { margin-top: 48px; }
        .line { border-top: 1px solid #94a3b8; width: 220px; margin-top: 40px; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="company">{{ $offer->company?->name }}</div>
        <div class="muted">{{ $offer->company?->address }} · {{ $offer->company?->email }} · {{ $offer->company?->phone }}</div>
    </div>

    <p>{{ now()->format('j F Y') }}</p>
    <p><strong>{{ $offer->applicant?->name }}</strong><br>{{ $offer->applicant?->email }}</p>

    <h2>Offer of Employment — {{ $offer->applicant?->posting?->title }}</h2>
    <p>Dear {{ \Illuminate\Support\Str::of($offer->applicant?->name)->before(' ') }},</p>
    <p>Following our recent discussions, we are pleased to offer you the position of
        <strong>{{ $offer->applicant?->posting?->title }}</strong>@if($offer->applicant?->posting?->department) in our {{ $offer->applicant->posting->department->name }} department @endif
        on the terms below.</p>

    <table class="terms">
        <tr><td class="k">Position</td><td>{{ $offer->applicant?->posting?->title }}</td></tr>
        <tr><td class="k">Gross monthly salary</td><td>KES {{ number_format((float) $offer->offered_salary, 2) }}</td></tr>
        <tr><td class="k">Start date</td><td>{{ $offer->start_date?->format('j F Y') }}</td></tr>
        <tr><td class="k">Location</td><td>{{ $offer->applicant?->posting?->location ?? 'As assigned' }}</td></tr>
        <tr><td class="k">Offer valid until</td><td>{{ $offer->expiry_date?->format('j F Y') }}</td></tr>
    </table>

    @if($offer->notes)
        <p>{!! nl2br(e($offer->notes)) !!}</p>
    @endif

    <p>This offer is subject to satisfactory references, verification of your academic and professional certificates,
        and a probation period in line with company policy. Statutory deductions (PAYE, NSSF, SHIF and the Housing Levy)
        apply in accordance with Kenyan law.</p>
    <p>Please confirm your acceptance by signing and returning a copy of this letter before the expiry date above.</p>

    <div class="sign">
        <p>Yours sincerely,</p>
        <div class="line">Human Resources<br><span class="muted">{{ $offer->company?->name }}</span></div>
    </div>

    <div class="sign">
        <p><strong>Acceptance</strong> — I accept the offer on the terms set out above.</p>
        <div class="line">Signature &amp; date</div>
    </div>
</body>
</html>
