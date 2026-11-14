<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">
    <title>Email alerts | {{ $brokerage }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --text: #0f172a; --muted: #475569; --btn: #0f172a; --btn-text: #fff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #020617; --text: #f1f5f9; --muted: #94a3b8; --btn: #f1f5f9; --btn-text: #0f172a; } }
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: var(--bg); color: var(--text); }
        main { max-width: 30rem; padding: 2rem 1rem; text-align: center; }
        p { color: var(--muted); line-height: 1.6; }
        button { padding: .7rem 1.2rem; font: inherit; font-weight: 600; border: 0; border-radius: .5rem; background: var(--btn); color: var(--btn-text); cursor: pointer; }
    </style>
</head>
<body>
<main>
    @if ($done)
        <h1>You're unsubscribed</h1>
        <p>You won't get alerts for "{{ $name }}" any more.</p>
    @else
        <h1>Stop these alerts?</h1>
        <p>You'll no longer get emails for "{{ $name }}" from {{ $brokerage }}.</p>
        <form method="post" action="{{ $action }}"><button type="submit">Stop alerts</button></form>
    @endif
</main>
</body>
</html>
