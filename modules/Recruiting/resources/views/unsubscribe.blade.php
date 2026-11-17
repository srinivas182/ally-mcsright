<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
    <title>{{ $done ? 'Unsubscribed' : 'Stop recruiting emails' }}</title>
    <style>
        :root { --bg:#f6f6f4; --fg:#1f2328; --card:#fff; --muted:#5b616b; --accent:#1d4ed8 }
        @media (prefers-color-scheme: dark) { :root { --bg:#111317; --fg:#e8e9eb; --card:#1b1e24; --muted:#a3a9b3; --accent:#7aa2ff } }
        body { margin:0; background:var(--bg); color:var(--fg); font:18px/1.55 system-ui, sans-serif; }
        main { max-width:520px; margin:12vh auto; padding:28px 16px; background:var(--card); border-radius:10px; }
        button { font:inherit; padding:12px 20px; border:0; border-radius:8px; background:var(--accent); color:#fff; cursor:pointer; }
        p.muted { color:var(--muted); font-size:15px; }
    </style>
</head>
<body>
<main>
    @if ($done)
        <h1>You're unsubscribed</h1>
        <p>{{ $brokerage }} won't send you any more recruiting emails.</p>
    @else
        <h1>Stop recruiting emails?</h1>
        <p>{{ $brokerage }} will stop sending you emails about working there.</p>
        <form method="post">
            <button type="submit">Stop the emails</button>
        </form>
    @endif
    <p class="muted">Texts, if you agreed to them, stop when you reply STOP.</p>
</main>
</body>
</html>
