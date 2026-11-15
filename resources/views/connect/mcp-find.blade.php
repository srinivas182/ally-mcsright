<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Connect your AI assistant | RightAlly</title>
    <style>
        :root { --bg: #ffffff; --fg: #111827; --muted: #4b5563; --line: #d1d5db; --accent: #1d4ed8; --accent-fg: #ffffff; --error: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0b1220; --fg: #f3f4f6; --muted: #9ca3af; --line: #374151; --accent: #3b82f6; --error: #f87171; } }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg); font: 18px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 28rem; margin: 0 auto; padding: 2.5rem 1rem; display: grid; gap: 1.25rem; }
        h1 { font-size: 1.5rem; margin: 0; }
        p { margin: 0; color: var(--muted); }
        label { display: grid; gap: .4rem; font-weight: 600; }
        input { font: inherit; padding: .75rem; border: 1px solid var(--line); border-radius: .5rem; background: transparent; color: var(--fg); }
        button { font: inherit; font-weight: 600; min-height: 3rem; padding: .75rem 1rem; border-radius: .5rem; border: 1px solid var(--accent); background: var(--accent); color: var(--accent-fg); cursor: pointer; width: 100%; }
        button.secondary { background: transparent; color: var(--fg); border-color: var(--line); }
        .error { color: var(--error); font-weight: 600; }
        form { display: grid; gap: .75rem; }
    </style>
</head>
<body>
<main>
    <h1>Connect {{ $client ?? 'your AI assistant' }} to RightAlly</h1>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    @if ($pending)
        @if ($remembered)
            <form method="post" action="/authorize/choose">
                @csrf
                <input type="hidden" name="pending" value="{{ $pending }}">
                <input type="hidden" name="brokerage" value="{{ $remembered['ulid'] }}">
                <button type="submit">Continue with {{ $remembered['name'] }}</button>
            </form>
            <p>Or sign in with another email:</p>
        @endif
        @if ($choices)
            <p>Your email works at more than one brokerage. Which one?</p>
            @foreach ($choices as $choice)
                <form method="post" action="/authorize/choose">
                    @csrf
                    <input type="hidden" name="pending" value="{{ $pending }}">
                    <input type="hidden" name="brokerage" value="{{ $choice['ulid'] }}">
                    <button type="submit" class="secondary">{{ $choice['name'] }}</button>
                </form>
            @endforeach
        @else
            <p>Enter the email you use to sign in to RightAlly. We'll take you to your brokerage's sign-in, then you press <strong>Allow</strong>.</p>
            <form method="post" action="/authorize/find">
                @csrf
                <input type="hidden" name="pending" value="{{ $pending }}">
                <label>Your email
                    <input type="email" name="email" autocomplete="email" required autofocus>
                </label>
                <button type="submit">Continue</button>
            </form>
            <p>Your AI assistant will see and change only what you can in RightAlly. You can disconnect it at any time under Apps.</p>
        @endif
    @endif
</main>
</body>
</html>
