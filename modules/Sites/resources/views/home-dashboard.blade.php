@php
    $points = collect($history)->take(-12)->values();
    $min = $points->min('mid') ?? 0;
    $max = $points->max('mid') ?? 0;
    $span = max(1, $max - $min);
    $poly = $points->map(fn ($p, $i) => round(($points->count() > 1 ? $i / ($points->count() - 1) : 0.5) * 560 + 20, 1).','.round(150 - (($p['mid'] - $min) / $span) * 110, 1))->implode(' ');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Your home: {{ $report->address }}</title>
<style>
    :root { --text:#0f172a; --muted:#475569; --line:#e2e8f0; --bg:#f8fafc; --card:#fff; --accent:#0f766e; color-scheme: light dark; }
    @media (prefers-color-scheme: dark) { :root { --text:#e2e8f0; --muted:#94a3b8; --line:#1e293b; --bg:#0b1220; --card:#111a2b; --accent:#2dd4bf; } }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--text); font:18px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif; }
    main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
    header { display:flex; align-items:center; gap:12px; margin-bottom: 16px; }
    header img { max-height: 40px; }
    h1 { font-size: 1.6rem; margin: 0 0 4px; }
    .muted { color: var(--muted); }
    .card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin: 16px 0; }
    .big { font-size: 2rem; font-weight: 700; margin: 4px 0; }
    .btn { display:inline-block; background: var(--accent); color:#fff; border:0; border-radius: 8px; padding: 12px 18px; font: inherit; font-weight: 600; text-decoration: none; cursor: pointer; }
    label { display:block; margin: 8px 0; }
    input { font: inherit; padding: 10px; border: 1px solid var(--line); border-radius: 8px; width: 100%; max-width: 280px; background: var(--card); color: var(--text); }
    ul.homes { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
    ul.homes li { border-top: 1px solid var(--line); padding-top: 12px; }
    svg { width: 100%; height: auto; }
    .fine { font-size: 13px; color: var(--muted); }
</style>
</head>
<body>
<main>
    <header>@if ($logo)<img src="{{ $logo }}" alt="{{ $brokerage }}">@else<strong>{{ $brokerage }}</strong>@endif</header>
    <h1>{{ $report->address }}</h1>
    <p class="muted">Your home dashboard{{ $agent ? ', from '.$agent->name : '' }}</p>

    <section class="card" aria-labelledby="value">
        <h2 id="value" class="muted" style="font-size:1rem;margin:0">Likely worth today</h2>
        @if ($estimate)
            <p class="big">${{ number_format($estimate['low']) }} – ${{ number_format($estimate['high']) }}</p>
            <p class="fine">From {{ $estimate['comps'] }} similar homes for sale near you. Asking prices can differ from what homes sell for; your agent can confirm it with recent sales.</p>
        @else
            <p>There aren't enough similar homes for sale near you this month to estimate online. Your agent can prepare a range from recent sales.</p>
        @endif
        @if ($points->count() >= 2)
            <svg viewBox="0 0 600 170" role="img" aria-label="Your home's estimated value by month: {{ $points->map(fn ($p) => substr($p['on'], 0, 7).' $'.number_format($p['mid']))->implode(', ') }}">
                <polyline fill="none" stroke="currentColor" stroke-width="3" points="{{ $poly }}" style="color:var(--accent)"/>
            </svg>
            <p class="fine">{{ substr($points->first()['on'], 0, 7) }} to {{ substr($points->last()['on'], 0, 7) }}</p>
        @endif
    </section>

    @if ($estimate)
        <section class="card" aria-labelledby="equity">
            <h2 id="equity" style="font-size:1.2rem;margin:0 0 8px">Your equity</h2>
            <p class="muted">Type what you still owe on your mortgage. It stays on this screen; we don't send or keep it.</p>
            <label>Mortgage balance ($) <input inputmode="numeric" data-balance placeholder="250,000"></label>
            <p class="big" data-equity aria-live="polite"></p>
        </section>
        <script>
            (function () {
                var mid = {{ (int) $estimate['mid'] }}, input = document.querySelector('[data-balance]'), out = document.querySelector('[data-equity]');
                input.addEventListener('input', function () {
                    var owed = parseInt(input.value.replace(/[^\d]/g, ''), 10);
                    out.textContent = isNaN(owed) ? '' : 'About $' + Math.max(0, mid - owed).toLocaleString('en-US') + ' in equity';
                });
            })();
        </script>
    @endif

    <section class="card" aria-labelledby="ask">
        <h2 id="ask" style="font-size:1.2rem;margin:0 0 8px">Want an exact number?</h2>
        @if ($asked)
            <p role="status"><strong>Thank you.</strong> {{ $agent?->first_name ?? 'Your agent' }} will prepare a full market analysis with recent sales and be in touch.</p>
        @else
            <p class="muted">{{ $agent?->first_name ?? 'Your agent' }} can prepare a full market analysis with recent sales and your home's features. No obligation.</p>
            <form method="post" action="{{ $action }}"><button class="btn" type="submit">Ask for a market analysis</button></form>
        @endif
        @if ($agent && $agent->phone)<p>Or call {{ $agent->first_name }}: <a href="tel:{{ preg_replace('/[^\d+]/', '', (string) $agent->phone) }}">{{ $agent->phone }}</a></p>@endif
    </section>

    @if ($nearby !== [])
        <section class="card" aria-labelledby="nearby">
            <h2 id="nearby" style="font-size:1.2rem;margin:0 0 8px">For sale near you</h2>
            <ul class="homes">
                @foreach ($nearby as $l)
                    <li>
                        <strong>{{ isset($l['price']) ? '$'.number_format((int) $l['price']) : '' }}</strong> · {{ $l['beds'] ?? '—' }} bd · {{ $l['baths_full'] ?? '—' }} ba @if (! empty($l['sqft']))· {{ number_format((int) $l['sqft']) }} sq ft @endif<br>
                        <span class="muted">{{ collect([$l['address'] ?? null, $l['city'] ?? null])->filter()->implode(', ') }}</span>
                        @if (! empty($l['url']))<br><a href="{{ $l['url'] }}">See photos and details</a>@endif
                        <div class="fine">{{ $l['attribution'] ?? '' }}</div>
                    </li>
                @endforeach
            </ul>
            @foreach (collect($nearby)->pluck('disclaimer')->filter()->unique() as $d)<p class="fine">{{ $d }}</p>@endforeach
        </section>
    @endif
    <p class="fine">{{ $brokerage }} · Equal Housing Opportunity</p>
</main>
</body>
</html>
