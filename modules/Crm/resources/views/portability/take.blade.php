{{-- The portability guarantee (specs/grow/03): an agent who left downloads their own people. Private, not indexed. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Take your people with you</title>
    <style>
        :root{--ink:#111827;--muted:#4b5563;--line:#e5e7eb;--bg:#f8fafc;--card:#fff;--brand:#4338ca}
        @media (prefers-color-scheme:dark){:root{--ink:#f3f4f6;--muted:#a1a1aa;--line:#2e3340;--bg:#0b0f19;--card:#111827;--brand:#a5b4fc}}
        body{margin:0;background:var(--bg);color:var(--ink);font:400 18px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
        main{max-width:640px;margin:48px auto;padding:0 16px}.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:24px}
        h1{font-size:30px;line-height:1.2;margin:0 0 12px}.muted{color:var(--muted)}
        button{min-height:56px;padding:0 26px;border-radius:14px;border:0;background:var(--brand);color:#fff;font:700 19px/1 inherit;cursor:pointer}
        @media (prefers-color-scheme:dark){button{color:#111827}}
        :focus-visible{outline:3px solid var(--brand);outline-offset:2px}
    </style>
</head>
<body>
<main>
    <div class="card">
        @if ($ok)
            <h1>Take your people with you</h1>
            <p>{{ number_format($people) }} people you added yourself, with their notes and tags.</p>
            <form method="post" action="">
                @csrf
                <button type="submit">Download my people</button>
            </form>
            <p class="muted">The file opens in any spreadsheet program and imports straight into another RightAlly.</p>
        @else
            <h1>This link has expired</h1>
            <p class="muted">Download links work for 30 days and up to five downloads. Ask your former broker to send a new one.</p>
        @endif
    </div>
</main>
</body>
</html>
