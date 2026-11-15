<!DOCTYPE html>
<html lang="en"><body style="font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #0f172a; padding: 24px;">
<p style="font-size: 18px; font-weight: 700; margin: 0 0 12px;">You're booked: {{ $title }}</p>
<p style="margin: 0 0 12px;">{{ $when }}</p>
<p style="margin: 0 0 12px;">{{ $agentName }} from {{ $siteName }} @if ($kind === 'call') will call you then. @else will confirm the details with you before then. @endif</p>
@if ($agentPhone !== '')<p style="margin: 0 0 12px;">Need to change it? Call or text {{ $agentPhone }}.</p>@endif
<p style="color: #64748b; font-size: 13px;">The calendar invite is attached.</p>
</body></html>
