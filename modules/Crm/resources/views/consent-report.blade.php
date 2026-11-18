<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>Consent report: {{ $contact->name }} | {{ $brokerage }}</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; color: #0f172a; background: #fff; margin: 0; }
        main { max-width: 900px; margin: 0 auto; padding: 32px 20px; }
        h1 { font-size: 24px; margin: 0 0 4px; } .muted { color: #475569; } small { color: #475569; font-size: 11px; word-break: break-all; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; } th, td { text-align: left; vertical-align: top; padding: 6px 4px; border-bottom: 1px solid #e2e8f0; }
        .actions a, .actions button { margin-right: 8px; font: inherit; } @media print { .actions { display: none; } main { padding: 0; } }
    </style>
</head>
<body>
<main>
    <p class="muted">{{ $brokerage }} · Consent report · {{ now()->format('m/d/Y H:i') }}</p>
    <h1>{{ $contact->name }}</h1>
    <p>{{ $contact->primary_phone }} {{ $contact->primary_email ? '· '.$contact->primary_email : '' }}</p>
    <p><strong>Texting consent:</strong> {{ ['yes' => 'Yes, written, with proof', 'verbal' => 'Verbal only (manual texts only)', 'no' => 'No (opted out)', 'unknown' => 'Unknown (never given here)'][$status] }}.
        Automated texts {{ $auto ? 'allowed' : 'not allowed' }}. Email: {{ $contact->email_opt_in ? 'yes' : 'not given or opted out' }}. Calls: {{ $contact->do_not_call ? 'do not call' : 'allowed' }}.</p>
    <p class="actions"><button type="button" data-print>Print or save as PDF</button><a href="?format=csv">Download CSV</a></p>
    <table>
        <tr><th>When</th><th>What</th><th>Where it came from</th><th>Wording and evidence</th></tr>
        @forelse ($events as $e)
            <tr>
                <td>{{ $e->occurred_at->format('m/d/Y H:i') }} UTC</td>
                <td>{{ ['sms' => 'Texts', 'email' => 'Email', 'call' => 'Calls'][$e->channel] ?? $e->channel }}: {{ $e->action === 'opt_in' ? 'agreed' : 'opted out' }}<br><small>{{ str_replace('_', ' ', $e->method) }}</small></td>
                <td>{{ str_replace('_', ' ', (string) ($e->source ?? 'not recorded')) }}@if ($e->source_ref)<br><small>{{ $e->source_ref }}</small>@endif
                    @if ($e->ip)<br><small>IP {{ $e->ip }}</small>@endif @if ($e->user_agent)<br><small>{{ $e->user_agent }}</small>@endif</td>
                <td>{{ $e->consent_text ?? '—' }}@if ($e->evidence_sha256)<br><small>Evidence SHA-256 {{ $e->evidence_sha256 }}</small>@endif</td>
            </tr>
        @empty
            <tr><td colspan="4" class="muted">No consent recorded. This person has never agreed to texts here, so they are never texted automatically.</td></tr>
        @endforelse
    </table>
    <p><small>Consent history is append-only: entries can't be changed or deleted. Each entry's evidence fingerprint lets you show it hasn't changed since it was recorded.</small></p>
</main>
<script nonce="{{ Vite::cspNonce() }}">document.addEventListener('click',function(e){var t=e.target;if(t.closest('[data-print]'))window.print();if(t.matches('[data-select]'))t.select();});</script>
</body>
</html>
