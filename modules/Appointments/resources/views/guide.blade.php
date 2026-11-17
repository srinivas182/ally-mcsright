@php($a = $answers)
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;color:#0f172a;font-size:16px;line-height:1.55;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;padding:16px;">
<tr><td>
<p>Hi {{ $firstName }},</p>
@if ($kind === 'listing')
    <p>Thank you for your time today. Here is the plan we talked about for {{ $a['home']['street'] ?? 'your home' }}.</p>
    @if (! empty($a['price']['list_price']))<p><strong>List price:</strong> ${{ number_format((float) $a['price']['list_price']) }}</p>@endif
    <p><strong>How we'll market it:</strong></p>
    <ul>@foreach (\Modules\Appointments\Consultations::MARKETING as $k => $label)@if (! empty($a['marketing'][$k]))<li>{{ $label }}</li>@endif @endforeach</ul>
    <p><strong>Listing agreement:</strong> {{ $a['terms']['listing_pct'] ?? '—' }}% to the listing brokerage for {{ $a['terms']['months'] ?? 6 }} months.
        @if (! empty($a['terms']['buyer_pct'])) You chose to offer {{ $a['terms']['buyer_pct'] }}% to the buyer's agent. @else You chose not to offer buyer's agent compensation up front; we'll handle any request in the offer. @endif
        Commissions are not set by law and are fully negotiable.</p>
    <p><strong>Next steps:</strong> sign the listing agreement, book the photographer, and finish the seller's disclosure. I'll send each one to you.</p>
@else
    <p>Thank you for meeting with me. Here is your home search plan.</p>
    <p><strong>What you're looking for:</strong> {{ collect([$a['needs']['beds'] ?? null ? ($a['needs']['beds'].'+ bedrooms') : null, $a['needs']['areas'] ?? null, $a['money']['budget'] ?? null ? ('up to $'.number_format((float) $a['money']['budget'])) : null])->filter()->implode(' · ') ?: 'we\'ll refine it together' }}</p>
    <p><strong>Our agreement:</strong> {{ \Modules\Appointments\Consultations::compensation((array) ($a['agreement'] ?? [])) }}, for {{ $a['agreement']['months'] ?? 3 }} months{{ ! empty($a['agreement']['exclusive']) ? ', exclusive' : '' }}.
        If a seller offers to pay some or all of it, that amount is credited. You never pay more than we agreed. Compensation is not set by law and is fully negotiable.
        {{ $agreementSigned ? 'Signed, thank you.' : 'I\'ll send it to you to sign before we tour homes.' }}</p>
    <p><strong>Next steps:</strong> get your pre-approval letter, and I'll set up a search that sends you new homes the moment they list.</p>
@endif
<p>Any questions, just reply{{ $agentPhone !== '' ? ' or call me at '.$agentPhone : '' }}.</p>
<p>{{ $agent }}<br>{{ $brokerage }}</p>
<p style="font-size:12px;color:#64748b;">Equal Housing Opportunity.</p>
</td></tr></table>
</body></html>
