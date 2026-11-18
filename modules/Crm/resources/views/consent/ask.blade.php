{{-- CN1 (specs/grow/18): one-tap texting consent. Plain, large, works without JavaScript; never indexed or cached. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Texts from {{ $brokerage }}</title>
    <style>
        :root{color-scheme:light dark;--ink:#111827;--muted:#4b5563;--bg:#fff;--line:#e5e7eb;--brand:#4338ca}
        @media (prefers-color-scheme:dark){:root{--ink:#f3f4f6;--muted:#cbd5e1;--bg:#0b0f19;--line:#2e3340;--brand:#a5b4fc}}
        body{margin:0;background:var(--bg);color:var(--ink);font:20px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}
        main{max-width:34rem;margin:0 auto;padding:40px 16px}h1{font-size:28px;line-height:1.2;margin:0 0 12px}
        .words{border:1px solid var(--line);border-radius:12px;padding:14px 16px;color:var(--muted);font-size:17px}
        button{font:700 20px/1 inherit;min-height:60px;width:100%;border-radius:14px;border:0;margin:10px 0 0;cursor:pointer}
        .yes{background:var(--brand);color:#fff}.no{background:transparent;color:var(--ink);border:1px solid var(--line)}
        @media (prefers-color-scheme:dark){.yes{color:#111827}}
    </style>
</head>
<body>
<main>
    @if ($state === 'ask')
        <h1>May {{ $brokerage }} text you?</h1>
        <p>Texts would go to your number ending {{ substr($phone, -4) }}.</p>
        <p class="words">{{ $wording }}</p>
        <form method="post" action="/consent/{{ $token }}">
            @csrf
            <button class="yes" type="submit" name="answer" value="yes">Yes, you may text me</button>
            <button class="no" type="submit" name="answer" value="no">No thanks</button>
        </form>
    @elseif ($state === 'yes')
        <h1>Thank you</h1>
        <p>{{ $brokerage }} may now text you. Reply STOP to any text to stop at any time.</p>
    @elseif ($state === 'no')
        <h1>No problem</h1>
        <p>{{ $brokerage }} will not text you. Nothing else changes.</p>
    @else
        <h1>This link has expired</h1>
        <p>Please ask your agent to send a new one.</p>
    @endif
</main>
</body>
</html>
