@extends('billing::health.layout', ['title' => 'Welcome to RightAlly', 'description' => 'Your RightAlly is being set up.', 'section' => 'Welcome', 'home' => '/start', 'signup' => true])

@if ($s->status === 'pending')
    @push('head')<meta http-equiv="refresh" content="4">@endpush
@endif

@section('main')
<section class="hero"><div class="wrap">
    @if ($s->status === 'live')
        <h1>{{ $s->brokerage }} is live</h1>
        <p class="lead">Your website is at <strong>https://{{ $website ?? $host }}</strong>. You and your agents sign in at <strong>{{ $website ?? $host }}/backoffice</strong>. We've emailed {{ $s->owner_email }} a link to sign in and set a password.</p>
        <ol class="lead">
            <li>Open the email and sign in.</li>
            <li>Go to <strong>Switch to RightAlly</strong> to bring your contacts and deals.</li>
            <li>Follow your 30-day go-live plan; your coach's name and contact details appear there once assigned.</li>
        </ol>
        <p><a class="btn" href="https://{{ $host }}/login">Go to my back office</a></p>
        @php($resent = session('resent'))
        @if ($resent === 'sent')
            <p class="card" role="status">We've sent the email again to {{ $s->owner_email }}. It can take a few minutes; check your spam or promotions folder too.</p>
        @elseif ($resent === 'signed-in')
            <p class="card" role="status">You've already set your password. Use "Forgot your password?" on <a href="https://{{ $host }}/login">your sign-in page</a> if you need a new one.</p>
        @else
            <form method="post" action="{{ route('signup.welcome.resend', $s->ulid) }}" class="muted small">@csrf Didn't get the email? <button class="link" type="submit">Send it again</button></form>
        @endif
    @elseif ($s->status === 'failed')
        <h1>Payment received — we'll finish your setup</h1>
        <p class="lead">Something stopped us creating {{ $s->brokerage }} automatically. Your payment is safe. Our team has been told and will email {{ $s->owner_email }} to finish it.</p>
    @else
        <h1>Setting up {{ $s->brokerage }}…</h1>
        <p class="lead" role="status">Thank you. Please keep this page open: it moves on by itself, usually in under a minute.</p>
        <div class="progress" aria-hidden="true"><span></span></div>
        <ol class="setup-steps">
            <li class="doing">Confirming your payment</li>
            <li>Creating your back office</li>
            <li>Building your website</li>
            <li>Emailing you a link to sign in</li>
        </ol>
    @endif
</div></section>
@endsection
