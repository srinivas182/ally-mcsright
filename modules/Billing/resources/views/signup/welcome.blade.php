@extends('billing::health.layout', ['title' => 'Welcome to RightAlly', 'description' => 'Your RightAlly is being set up.', 'section' => 'Welcome', 'home' => '/start', 'signup' => true])

@if ($s->status === 'pending')
    @push('head')<meta http-equiv="refresh" content="5">@endpush
@endif

@section('main')
<section class="hero"><div class="wrap">
    @if ($s->status === 'live')
        <h1>{{ $s->brokerage }} is live</h1>
        <p class="lead">Your RightAlly is at <strong>https://{{ $host }}</strong>. We've emailed {{ $s->owner_email }} a link to sign in and set a password.</p>
        <ol class="lead">
            <li>Open the email and sign in.</li>
            <li>Go to <strong>Switch to RightAlly</strong> to bring your contacts and deals.</li>
            <li>Follow your 30-day go-live plan; your coach's name and contact details appear there once assigned.</li>
        </ol>
        <p><a class="btn" href="https://{{ $host }}/login">Go to my RightAlly</a></p>
    @elseif ($s->status === 'failed')
        <h1>Payment received — we'll finish your setup</h1>
        <p class="lead">Something stopped us creating {{ $s->brokerage }} automatically. Your payment is safe. Our team has been told and will email {{ $s->owner_email }} to finish it.</p>
    @else
        <h1>Setting up {{ $s->brokerage }}…</h1>
        <p class="lead" role="status">We're waiting for the payment to be confirmed. This page refreshes by itself; it usually takes under a minute.</p>
    @endif
</div></section>
@endsection
