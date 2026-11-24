@extends('billing::health.layout', ['title' => 'Welcome to RightAlly', 'description' => 'Your RightAlly is being set up.', 'section' => 'Welcome', 'home' => '/start', 'signup' => true])

@php
    $first = trim((string) strtok(trim((string) $s->owner_name), ' ')) ?: 'there';
    $signerName = (string) ($signer['name'] ?? 'Sunil');
    $signerTitle = (string) ($signer['title'] ?? 'CEO, RightAlly');
@endphp

@if ($s->status === 'pending')
    {{-- Without JavaScript the page still moves on by itself. --}}
    @push('head')<noscript><meta http-equiv="refresh" content="5"></noscript>@endpush
@endif

@section('main')
<section class="hero welcome"><div class="wrap">
    @if ($s->status === 'live')
        {{-- EM5: a personal welcome, from the founder, with the one thing to do next. --}}
        <p class="welcome-kicker">Payment confirmed · {{ $s->brokerage }} is live</p>
        <h1>Welcome to the RightAlly family, {{ $first }}.</h1>
        <p class="lead">Thank you for choosing us to run {{ $s->brokerage }}. It means a great deal that you trusted us with something you have worked so hard to build, and we will look after it as if it were our own.</p>

        <div class="welcome-next card">
            <span class="welcome-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4a2 2 0 0 1-2-2V6c0-1.1.9-2 2-2zm8 7 8-5H4l8 5zm0 2L4 8v10h16V8l-8 5z"/></svg></span>
            <div>
                <h2>Your next step: check your email</h2>
                <p>We have sent a welcome email to <strong>{{ $s->owner_email }}</strong>. Open it and choose <strong>Set my password</strong>: that is your key to your back office. It usually arrives within a minute; if you cannot see it, look in your spam or promotions folder.</p>
                @php($resent = session('resent'))
                @if ($resent === 'sent')
                    <p class="welcome-note" role="status">Done: we have sent it again to {{ $s->owner_email }}.</p>
                @elseif ($resent === 'signed-in')
                    <p class="welcome-note" role="status">You have already set your password. Sign in below, or use "Forgot your password?" on the sign-in page if you need a new one.</p>
                @else
                    <form method="post" action="{{ route('signup.welcome.resend', $s->ulid) }}" class="small">@csrf No email yet? <button class="link" type="submit">Send it again</button></form>
                @endif
            </div>
        </div>

        <div class="welcome-places">
            <div class="card">
                <p class="small muted">Your back office</p>
                <p><strong>{{ $host }}</strong></p>
                <p class="small muted">Where you and your agents sign in every day. Bookmark it.</p>
                <a class="btn" href="https://{{ $host }}/login">Go to my back office</a>
            </div>
            <div class="card">
                <p class="small muted">Your website</p>
                <p><strong>{{ $website ?? $host }}</strong></p>
                <p class="small muted">Live now, in the design you chose, with sample homes until your MLS board approves.</p>
                <a class="btn ghost" href="https://{{ $website ?? $host }}" target="_blank" rel="noopener">See my website</a>
            </div>
        </div>

        <h2 class="welcome-h2">Your first week</h2>
        <ol class="welcome-steps">
            <li><strong>Set your password</strong> from the welcome email.</li>
            <li><strong>Add your licence and your MLS board</strong> under Settings, so real listings replace the samples.</li>
            <li><strong>Bring your contacts and deals</strong> with Switch to RightAlly. We do the heavy lifting.</li>
            <li><strong>Invite your agents</strong> under Team when you are ready.</li>
        </ol>

        <div class="welcome-letter">
            <p>{{ $first }}, our whole team is here for you, from today and for as long as you are with us. If anything is unclear, simply reply to any of our emails and a real person will answer.</p>
            <p>Welcome aboard.</p>
            <p class="welcome-sign">Warm regards,<br><strong>{{ $signerName }}</strong><br><span class="muted">{{ $signerTitle }}</span></p>
        </div>
    @elseif ($s->status === 'failed')
        <h1>Thank you, {{ $first }}. Your payment is safe.</h1>
        <p class="lead">Something stopped us finishing {{ $s->brokerage }} automatically. Our team has already been told and will email {{ $s->owner_email }} shortly to complete it with you. You do not need to pay again.</p>
        <p class="welcome-sign">With our apologies,<br><strong>{{ $signerName }}</strong><br><span class="muted">{{ $signerTitle }}</span></p>
    @else
        {{-- EM5: live progress while the payment is confirmed and everything is built (usually under a minute). --}}
        <p class="welcome-kicker">Thank you, {{ $first }}</p>
        <h1>We are setting up {{ $s->brokerage }}</h1>
        <p class="lead" role="status" data-setup-message>Your payment went through. We are now building your back office and your website. This usually takes under a minute; please keep this page open.</p>
        <div class="setup-bar" role="progressbar" aria-label="Setting up" aria-valuemin="0" aria-valuemax="100" aria-valuenow="8" data-setup-bar><span style="width:8%"></span></div>
        <ol class="setup-steps" data-setup-steps>
            <li class="doing">Confirming your payment</li>
            <li>Creating your back office</li>
            <li>Building your website</li>
            <li>Emailing you a link to set your password</li>
        </ol>
        <p class="small muted" data-setup-slow hidden>This is taking a little longer than usual. Your payment is safe, and nothing is lost: we will email {{ $s->owner_email }} the moment everything is ready. You can keep this page open or close it.</p>
        <script nonce="{{ Vite::cspNonce() }}">
        (function () {
            var started = Date.now(), steps = document.querySelectorAll('[data-setup-steps] li'), bar = document.querySelector('[data-setup-bar]');
            var marks = [0, 6, 18, 32]; // seconds at which each step usually begins
            function show(seconds) {
                var at = 0;
                marks.forEach(function (m, i) { if (seconds >= m) { at = i; } });
                steps.forEach(function (li, i) { li.className = i < at ? 'done' : (i === at ? 'doing' : ''); });
                var pct = Math.min(92, Math.round(8 + 84 * (1 - Math.exp(-seconds / 28))));
                bar.setAttribute('aria-valuenow', pct); bar.firstElementChild.style.width = pct + '%';
                if (seconds > 100) { document.querySelector('[data-setup-slow]').hidden = false; }
            }
            function check() {
                fetch('{{ route('signup.welcome.status', $s->ulid) }}', { headers: { Accept: 'application/json' }, cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.status === 'live' || d.status === 'failed') {
                            steps.forEach(function (li) { li.className = 'done'; });
                            bar.firstElementChild.style.width = '100%';
                            document.querySelector('[data-setup-message]').textContent = 'All done. Taking you to your welcome page…';
                            setTimeout(function () { window.location.reload(); }, 900);
                        } else { setTimeout(check, 3000); }
                    })
                    .catch(function () { setTimeout(check, 5000); });
            }
            setInterval(function () { show((Date.now() - started) / 1000); }, 500);
            show(0); setTimeout(check, 2000);
        })();
        </script>
    @endif
</div></section>
@endsection
