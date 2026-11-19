<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title }} — cheat sheet</title>
    <style>
        body { font: 18px/1.6 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; margin: 0 auto; max-width: 46rem; padding: 2rem 1.5rem; }
        h1 { font-size: 2rem; margin: 0 0 .25rem; }
        .summary { font-size: 1.2rem; margin: 0 0 1.5rem; }
        .body h2, .body h3 { margin-top: 1.5rem; }
        .body li { margin-bottom: .5rem; }
        .body strong { font-weight: 700; }
        .actions { margin-bottom: 1.5rem; }
        .actions button { font-size: 1.1rem; padding: .6rem 1.2rem; border-radius: .5rem; border: 2px solid #111; background: #fff; cursor: pointer; }
        footer { margin-top: 2rem; border-top: 1px solid #999; padding-top: .75rem; font-size: .9rem; color: #444; }
        @media print { .actions { display: none; } body { padding: 0; } a { color: #111; text-decoration: none; } }
    </style>
</head>
<body>
    <p class="actions"><button type="button" data-print>Print this page</button></p>
    <h1>{{ $title }}</h1>
    <p class="summary">{{ $summary }}</p>
    <div class="body">{!! $html !!}</div>
    <footer>RightAlly cheat sheet · Need more help? Press <strong>Help</strong> on any page, or ask for a call back.</footer>
    <script nonce="{{ Vite::cspNonce() }}">document.addEventListener('click',function(e){if(e.target.closest('[data-print]'))window.print();});</script>
</body>
</html>
