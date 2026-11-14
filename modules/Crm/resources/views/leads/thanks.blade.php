<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Thank you | {{ $brokerage }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --text: #0f172a; --muted: #475569; }
        @media (prefers-color-scheme: dark) { :root { --bg: #020617; --text: #f1f5f9; --muted: #94a3b8; } }
        body { font-family: system-ui, sans-serif; display: grid; place-items: center; min-height: 100vh; margin: 0; background: var(--bg); color: var(--text); }
        main { max-width: 32rem; padding: 2rem 1rem; text-align: center; }
        p { color: var(--muted); line-height: 1.6; }
    </style>
</head>
<body>
<main>
    <h1>Thank you</h1>
    <p>An agent from {{ $brokerage }} will be in touch shortly.</p>
</main>
</body>
</html>
