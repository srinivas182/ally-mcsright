<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <title>{{ $source->heading }} | {{ $brokerage }}</title>
    <meta name="description" content="{{ $source->heading }} — contact {{ $brokerage }}. A licensed agent will get back to you quickly.">
    <link rel="canonical" href="{{ $canonical }}">
    <script type="application/ld+json">{!! $schema !!}</script>
    <style>
        :root { color-scheme: light dark; --bg: #f8fafc; --card: #fff; --text: #0f172a; --muted: #475569; --line: #cbd5e1; --accent: #0f172a; --accent-text: #fff; --err: #b91c1c; }
        @media (prefers-color-scheme: dark) { :root { --bg: #020617; --card: #0f172a; --text: #f1f5f9; --muted: #94a3b8; --line: #334155; --accent: #f1f5f9; --accent-text: #0f172a; --err: #f87171; } }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: var(--bg); color: var(--text); line-height: 1.5; }
        main { max-width: 34rem; margin: 0 auto; padding: 2rem 1rem; }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: .75rem; padding: 1.5rem; }
        h1 { font-size: 1.5rem; margin: 0 0 .25rem; }
        p.lead { color: var(--muted); margin: 0 0 1.25rem; }
        label { display: block; font-weight: 600; font-size: .9rem; margin-top: .9rem; }
        input, textarea, select { width: 100%; font: inherit; padding: .6rem .7rem; border: 1px solid var(--line); border-radius: .5rem; background: transparent; color: inherit; margin-top: .25rem; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
        .consent { display: flex; gap: .6rem; align-items: flex-start; font-weight: 400; font-size: .8rem; color: var(--muted); }
        .consent input { width: auto; margin-top: .2rem; }
        .err { color: var(--err); font-size: .85rem; margin: .25rem 0 0; }
        button { margin-top: 1.25rem; width: 100%; padding: .75rem; font: inherit; font-weight: 600; border: 0; border-radius: .5rem; background: var(--accent); color: var(--accent-text); cursor: pointer; }
        .hp { position: absolute; left: -5000px; }
        footer { color: var(--muted); font-size: .75rem; text-align: center; margin-top: 1rem; }
    </style>
</head>
<body>
<main>
    <div class="card">
        <h1>{{ $source->heading }}</h1>
        <p class="lead">Leave your details and an agent from {{ $brokerage }} will get back to you shortly.</p>
        <form method="post" action="{{ $action }}" novalidate>
            <div class="row">
                <div>
                    <label for="first_name">First name</label>
                    <input id="first_name" name="first_name" autocomplete="given-name" required value="{{ $old['first_name'] ?? '' }}" @if(isset($errors['first_name'])) aria-invalid="true" aria-describedby="first_name_err" @endif>
                    @isset($errors['first_name'])<p class="err" id="first_name_err">{{ $errors['first_name'][0] }}</p>@endisset
                </div>
                <div>
                    <label for="last_name">Last name</label>
                    <input id="last_name" name="last_name" autocomplete="family-name" value="{{ $old['last_name'] ?? '' }}">
                </div>
            </div>
            <label for="email">Email</label>
            <input id="email" type="email" name="email" autocomplete="email" value="{{ $old['email'] ?? '' }}" @if(isset($errors['email'])) aria-invalid="true" aria-describedby="email_err" @endif>
            @isset($errors['email'])<p class="err" id="email_err">{{ $errors['email'][0] }}</p>@endisset
            <label for="phone">Mobile phone</label>
            <input id="phone" type="tel" name="phone" autocomplete="tel" value="{{ $old['phone'] ?? '' }}">
            <label for="intent">I'm looking to</label>
            <select id="intent" name="intent">
                <option value="">Choose…</option>
                <option value="buy" @selected(($old['intent'] ?? '') === 'buy')>Buy a home</option>
                <option value="sell" @selected(($old['intent'] ?? '') === 'sell')>Sell my home</option>
                <option value="rent" @selected(($old['intent'] ?? '') === 'rent')>Rent a home</option>
            </select>
            <label for="message">How can we help?</label>
            <textarea id="message" name="message" rows="3">{{ $old['message'] ?? '' }}</textarea>
            <label class="consent"><input type="checkbox" name="sms_consent" value="1" @checked(! empty($old['sms_consent']))> <span>{{ $source->consent_wording }}</span></label>
            <div class="hp" aria-hidden="true"><label for="website">Leave empty</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
            <input type="hidden" name="first_referrer" value="{{ $old['first_referrer'] ?? $old['referrer'] ?? '' }}">
            <input type="hidden" name="landing_url" value="{{ $old['landing_url'] ?? '' }}">
            <input type="hidden" name="t" value="{{ $t }}">
            <button type="submit">Send</button>
        </form>
    </div>
    <footer>{{ $brokerage }} · Equal Housing Opportunity</footer>
</main>
</body>
</html>
