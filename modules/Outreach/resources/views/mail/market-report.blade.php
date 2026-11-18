@php($money = fn ($v) => $v === null ? '—' : '$'.number_format((int) $v))
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $stats['name'] }} market report</title></head>
<body style="margin:0;background:#f8fafc;font-family:system-ui,-apple-system,sans-serif;color:#0f172a;font-size:16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;padding:16px;">
    <tr><td style="padding:8px 0 16px;">
        <p style="margin:0 0 4px;">Hi {{ $firstName }},</p>
        <p style="margin:0;color:#475569;">Here is how the market in <strong>{{ $stats['name'] }}</strong> looks this month.</p>
    </td></tr>
    <tr><td style="padding:0 0 16px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;">
            <tr>
                <td style="padding:16px;width:50%;"><p style="margin:0;color:#475569;">Homes for sale</p><p style="margin:4px 0 0;font-size:24px;font-weight:700;">{{ number_format((int) $stats['active']) }}</p></td>
                <td style="padding:16px;width:50%;"><p style="margin:0;color:#475569;">Typical asking price</p><p style="margin:4px 0 0;font-size:24px;font-weight:700;">{{ $money($stats['median_price']) }}</p></td>
            </tr>
            <tr>
                <td style="padding:0 16px 16px;"><p style="margin:0;color:#475569;">New in the last 30 days</p><p style="margin:4px 0 0;font-size:20px;font-weight:700;">{{ (int) $stats['new_30'] }}</p></td>
                <td style="padding:0 16px 16px;"><p style="margin:0;color:#475569;">Price reduced</p><p style="margin:4px 0 0;font-size:20px;font-weight:700;">{{ (int) $stats['reduced_30'] }}</p></td>
            </tr>
            <tr><td colspan="2" style="padding:0 16px 16px;color:#475569;">
                @if ($stats['median_dom'] !== null)Homes are typically on the market for <strong>{{ (int) $stats['median_dom'] }} days</strong>. @endif
                Asking prices run from {{ $money($stats['low_price']) }} to {{ $money($stats['high_price']) }}@if ($stats['median_ppsf'] !== null), about {{ $money($stats['median_ppsf']) }} per square foot @endif.
            </td></tr>
        </table>
        <p style="margin:8px 0 0;font-size:12px;color:#64748b;">Source: MLS listings for sale, {{ \Carbon\CarbonImmutable::parse($stats['as_of'])->format('M j, Y') }}. Asking prices can differ from what homes sell for.</p>
    </td></tr>
    @if ($homes !== [])
        <tr><td style="padding:0 0 8px;font-weight:700;">New on the market</td></tr>
        @foreach ($homes as $l)
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
        Curious what this means for your home? Reply to this email{{ $agentPhone ? ' or call '.$agentPhone : '' }}.<br>{{ $agent ?? '' }}{{ $agent ? ', ' : '' }}{{ $brokerage }}
    </td></tr>
    <tr><td style="padding:16px 0;color:#64748b;font-size:12px;">
        You get this monthly from {{ $agent ?? $brokerage }} at {{ $brokerage }}. <a href="{{ $stopUrl }}" style="color:#64748b;">Stop these emails</a>.
        @foreach (collect($homes)->pluck('disclaimer')->filter()->unique() as $d)<br><br>{{ $d }}@endforeach
        <br><br>Equal Housing Opportunity.
    </td></tr>
</table>
</body>
</html>
