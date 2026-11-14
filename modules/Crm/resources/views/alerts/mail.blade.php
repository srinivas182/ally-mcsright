<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $searchName }}</title></head>
<body style="margin:0;background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;padding:16px;">
    <tr><td style="padding:8px 0 16px;">
        <p style="margin:0 0 4px;font-size:16px;">Hi {{ $firstName }},</p>
        <p style="margin:0;color:#475569;">Here's what's new for your search <strong>{{ $searchName }}</strong>.</p>
    </td></tr>
    @foreach ($listings as $l)
        <tr><td style="padding:0 0 16px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
                @if (! empty($l['photo']))
                    <tr><td><img src="{{ $l['photo'] }}" alt="{{ $l['address'] ?? 'Home' }}" width="568" style="display:block;width:100%;height:auto;border-radius:8px 8px 0 0;"></td></tr>
                @endif
                <tr><td style="padding:12px;">
                    <p style="margin:0;font-size:18px;font-weight:700;">
                        {{ isset($l['price']) ? '$'.number_format((int) $l['price']) : '' }}
                        @if ($l['kind'] === 'price_drop')<span style="font-size:12px;font-weight:600;color:#b91c1c;"> Price reduced</span>@else<span style="font-size:12px;font-weight:600;color:#15803d;"> New</span>@endif
                    </p>
                    <p style="margin:4px 0;">{{ $l['beds'] ?? '—' }} bd · {{ $l['baths_full'] ?? '—' }} ba @if (! empty($l['sqft']))· {{ number_format((int) $l['sqft']) }} sq ft @endif</p>
                    <p style="margin:0;color:#475569;">{{ collect([$l['address'] ?? null, $l['city'] ?? null, $l['state'] ?? null])->filter()->implode(', ') }}</p>
                    @if (! empty($l['url']))<p style="margin:8px 0 0;"><a href="{{ $l['url'] }}" style="color:#0f172a;font-weight:600;">See photos and details</a></p>@endif
                    <p style="margin:8px 0 0;font-size:11px;color:#64748b;">{{ $l['attribution'] }}</p>
                </td></tr>
            </table>
        </td></tr>
    @endforeach
    <tr><td style="padding:8px 0;color:#475569;font-size:14px;">
        Want to see any of these? Just reply to this email{{ $agentPhone ? ' or call '.$agentPhone : '' }}.<br>{{ $agent ?? '' }}{{ $agent ? ', ' : '' }}{{ $brokerage }}
    </td></tr>
    <tr><td style="padding:16px 0;color:#64748b;font-size:12px;">
        You get these emails because a saved search was set up for you at {{ $brokerage }}. <a href="{{ $stopUrl }}" style="color:#64748b;">Stop these alerts</a>.
        @php($disclaimers = collect($listings)->pluck('disclaimer')->filter()->unique())
        @foreach ($disclaimers as $d)<br><br>{{ $d }}@endforeach
        <br><br>Equal Housing Opportunity.
    </td></tr>
</table>
</body>
</html>
