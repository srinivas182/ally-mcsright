<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Your home value: {{ $address }}</title></head>
<body style="margin:0;background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;padding:16px;">
    <tr><td style="padding:8px 0 16px;">
        <p style="margin:0 0 4px;font-size:16px;">Hi {{ $firstName }},</p>
        <p style="margin:0;color:#475569;">Here is this month's update for <strong>{{ $address }}</strong>.</p>
    </td></tr>
    <tr><td style="padding:0 0 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
            <tr><td style="padding:16px;">
                @if ($estimate)
                    <p style="margin:0;color:#475569;">Likely worth today</p>
                    <p style="margin:4px 0;font-size:24px;font-weight:700;">${{ number_format((int) $estimate['low']) }} – ${{ number_format((int) $estimate['high']) }}</p>
                    @if ($change !== null)
                        <p style="margin:0;color:#475569;">{{ $change > 0 ? 'Up '.$change.'%' : ($change < 0 ? 'Down '.abs($change).'%' : 'About the same') }} since last month.</p>
                    @endif
                    <p style="margin:8px 0 0;font-size:12px;color:#64748b;">From {{ (int) $estimate['comps'] }} similar homes for sale near you. Asking prices can differ from what homes sell for; your agent can confirm it with recent sales and your home's condition.</p>
                @else
                    <p style="margin:0;">There aren't enough similar homes for sale near you this month to estimate online. Reply and your agent will prepare a price range from recent sales.</p>
                @endif
            </td></tr>
        </table>
    </td></tr>
    @if ($nearby !== [])
        <tr><td style="padding:0 0 8px;font-weight:700;">For sale near you</td></tr>
        @foreach ($nearby as $l)
            <tr><td style="padding:0 0 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;"><tr><td style="padding:12px;">
                    <p style="margin:0;font-weight:700;">{{ isset($l['price']) ? '$'.number_format((int) $l['price']) : '' }}</p>
                    <p style="margin:4px 0;">{{ $l['beds'] ?? '—' }} bd · {{ $l['baths_full'] ?? '—' }} ba @if (! empty($l['sqft']))· {{ number_format((int) $l['sqft']) }} sq ft @endif</p>
                    <p style="margin:0;color:#475569;">{{ collect([$l['address'] ?? null, $l['city'] ?? null])->filter()->implode(', ') }}</p>
                    @if (! empty($l['url']))<p style="margin:8px 0 0;"><a href="{{ $l['url'] }}" style="color:#0f172a;font-weight:600;">See photos and details</a></p>@endif
                    <p style="margin:8px 0 0;font-size:11px;color:#64748b;">{{ $l['attribution'] ?? '' }}</p>
                </td></tr></table>
            </td></tr>
        @endforeach
    @endif
    <tr><td style="padding:8px 0;color:#475569;font-size:14px;">
        Thinking about selling, or just curious? Reply to this email{{ $agentPhone ? ' or call '.$agentPhone : '' }}.<br>{{ $agent ?? '' }}{{ $agent ? ', ' : '' }}{{ $brokerage }}
    </td></tr>
    <tr><td style="padding:16px 0;color:#64748b;font-size:12px;">
        You get this monthly because you asked {{ $brokerage }} what your home is worth. <a href="{{ $stopUrl }}" style="color:#64748b;">Stop these emails</a>.
        @foreach (collect($nearby)->pluck('disclaimer')->filter()->unique() as $d)<br><br>{{ $d }}@endforeach
        <br><br>Equal Housing Opportunity.
    </td></tr>
</table>
</body>
</html>
