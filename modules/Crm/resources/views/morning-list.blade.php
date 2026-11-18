<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Your list for today</title>
    <style>
        body { font: 20px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; color: #111; background: #fafafa; margin: 0 auto; max-width: 40rem; padding: 1.25rem; }
        h1 { font-size: 1.8rem; margin: .25rem 0 1rem; }
        ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; }
        li { background: #fff; border: 2px solid #ddd; border-radius: 1rem; padding: 1rem; }
        .name { font-weight: 700; font-size: 1.3rem; }
        .why { color: #444; margin: .2rem 0 .8rem; }
        .call { display: block; text-align: center; background: #1d4ed8; color: #fff; text-decoration: none; font-size: 1.3rem; font-weight: 600; padding: .9rem; border-radius: .8rem; }
        .how { margin-top: .8rem; display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; }
        .how button { font: inherit; font-size: 1rem; padding: .7rem .3rem; border: 2px solid #1d4ed8; background: #fff; color: #1d4ed8; border-radius: .7rem; cursor: pointer; }
        .done { background: #dcfce7; border: 2px solid #16a34a; border-radius: .8rem; padding: .8rem 1rem; margin-bottom: 1rem; }
        .muted { color: #555; font-size: 1rem; }
        footer { margin-top: 1.5rem; font-size: .9rem; color: #555; }
    </style>
</head>
<body>
    <h1>Good morning, {{ $name }}</h1>
    @if ($done)
        <p class="done" role="status">Saved: {{ $done }}.</p>
    @endif
    @if ($people === [])
        <p>No one to call right now. Nice work.</p>
    @else
        <p class="muted">Call these people today. After each call, tap how it went.</p>
        <ol>
            @foreach ($people as $p)
                <li>
                    <div class="name">{{ $p['name'] }}</div>
                    <div class="why">{{ $p['reason'] }}</div>
                    @if ($p['call'] && $p['phone'])
                        <a class="call" href="tel:{{ $p['phone'] }}">Call</a>
                    @else
                        <p class="muted">No phone number we may call.</p>
                    @endif
                    <form class="how" method="post" action="{{ request()->fullUrl() }}">
                        @csrf
                        <input type="hidden" name="contact" value="{{ $p['ulid'] }}">
                        @foreach ($outcomes as $key => $label)
                            <button type="submit" name="outcome" value="{{ $key }}">{{ $label }}</button>
                        @endforeach
                    </form>
                </li>
            @endforeach
        </ol>
    @endif
    <footer>This page works until midnight and only shows today's list. Do not forward it. To see everything, sign in to RightAlly.</footer>
</body>
</html>
